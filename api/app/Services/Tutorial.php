<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\RunInputsFactory;
use App\Content\TutorialSteps;
use App\Domain\Run\Rarity;
use App\Models\CrateOpening;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\Travel;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WorkshopJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves a driver along the steps of the tutorial (content/rules/tutorial.json).
 *
 * The server is the source of truth: a step starts when the one before it is done and its trigger holds, and it is
 * done when its completion holds, both read from the state of the game (the run, the tank, the breakdown) or from the
 * driver answering the messages. Nothing listens for events: like the settlement of a run, the tutorial is brought up to
 * date whenever somebody looks at it, so it cannot miss what happened while the driver was away.
 *
 * A step that has messages is shown before it can be completed, so it ends the pass that started it. What a step pays
 * is posted once, with a key of its own, in the same transaction that marks it done. The wallet is locked first, as
 * everywhere in the game, so that two requests of one driver go one after another.
 */
final class Tutorial
{
    /** A tank under a quarter is low, and one at four fifths is filled. */
    private const LOW_FUEL_SHARE = 0.25;

    private const FILLED_FUEL_SHARE = 0.8;

    public function __construct(
        private readonly TutorialSteps $steps,
        private readonly RunService $runs,
        private readonly Workshop $workshop,
        private readonly RunInputsFactory $inputs,
        private readonly Ledger $ledger,
        private readonly Garage $garage,
        private readonly CarProgress $progress,
        private readonly Catalog $catalog,
        private readonly CityGarages $garages,
    ) {}

    /** Brings the tutorial of the driver up to date, which settles the run first, and tells where it stands. */
    public function evaluate(User $user): TutorialState
    {
        $userId = (int) $user->getKey();

        $this->workshop->advance($userId);
        $run = $this->garage->selectedRun($userId);

        if ($run !== null) {
            $this->runs->settle($run->id);
            $run->refresh();
        }

        return DB::transaction(function () use ($userId, $run): TutorialState {
            Wallet::query()->where('user_id', $userId)->lockForUpdate()->first();

            return $this->advance($userId, $run);
        }, attempts: 3);
    }

    /** The driver answered the messages of the step that waits: the phone is put away, and a step that ends with it is done. */
    public function reply(User $user): TutorialState
    {
        TutorialProgress::query()
            ->where('user_id', $user->getKey())
            ->where('state', TutorialProgress::STATE_ACTIVE)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]);

        return $this->evaluate($user);
    }

    /** Leaves out every step that is not done, with its reward. What was done stays done. */
    public function skip(User $user): TutorialState
    {
        $userId = (int) $user->getKey();

        return DB::transaction(function () use ($userId): TutorialState {
            Wallet::query()->where('user_id', $userId)->lockForUpdate()->first();

            $rows = $this->rows($userId);

            foreach ($this->steps->all() as $step) {
                $row = $rows->get($step['key']);

                if ($row?->state === TutorialProgress::STATE_DONE) {
                    continue;
                }

                TutorialProgress::query()->updateOrCreate(
                    ['user_id' => $userId, 'step_key' => $step['key']],
                    ['state' => TutorialProgress::STATE_SKIPPED, 'skipped_at' => now()],
                );
            }

            return $this->state($userId, []);
        }, attempts: 3);
    }

    /**
     * @return Collection<string, TutorialProgress>
     */
    private function rows(int $userId): Collection
    {
        return TutorialProgress::query()->where('user_id', $userId)->get()->keyBy('step_key');
    }

    private function advance(int $userId, ?RaceRun $run): TutorialState
    {
        $rows = $this->rows($userId);
        $rewarded = [];

        foreach ($this->steps->all() as $step) {
            $row = $rows->get($step['key']);

            if ($row !== null && $row->state !== TutorialProgress::STATE_ACTIVE) {
                continue;
            }

            if ($row === null) {
                if (! $this->holds($step['trigger'], $userId, $run, null)) {
                    break;
                }

                $row = TutorialProgress::query()->create([
                    'user_id' => $userId,
                    'step_key' => $step['key'],
                    'state' => TutorialProgress::STATE_ACTIVE,
                    'started_at' => now(),
                ]);

                // Shown first: it can be completed on the next look.
                if ($step['sms'] !== []) {
                    break;
                }
            }

            if (! $this->holds($step['completion'], $userId, $run, $row)) {
                break;
            }

            $row->update(['state' => TutorialProgress::STATE_DONE, 'completed_at' => now()]);

            $cents = (int) ($step['reward']['cash_cents'] ?? 0);
            $carXp = (int) ($step['reward']['car_xp'] ?? 0);
            $xp = (int) ($step['reward']['xp'] ?? 0);

            if ($cents > 0) {
                $this->ledger->post($userId, $cents, 'tutorial', "tutorial:{$userId}:{$step['key']}");
            }

            // The experience of the driver goes before the garage and the part, which may ask for a level: the steps are paid in the order of the design.
            $this->ledger->grantXp($userId, $xp);
            $part = isset($step['reward']['part']) ? $this->givePart($userId, $step['reward']['part']) : null;
            $garage = isset($step['reward']['garage']) && $this->garages->grant($userId, $step['reward']['garage']) ? $step['reward']['garage'] : null;

            // The experience goes to the car the driver has: with no car there is nothing to give it to.
            if ($carXp > 0) {
                $car = $this->garage->carOf($userId);
                $carXp = $car === null ? 0 : ($this->progress->award($car->id, $carXp) === null ? 0 : $carXp);
            }

            if ($cents > 0 || $carXp > 0 || $xp > 0 || $part !== null || $garage !== null) {
                $rewarded[] = ['key' => $step['key'], 'cash_cents' => $cents, 'car_xp' => $carXp, 'xp' => $xp, 'part' => $part, 'garage' => $garage];
            }
        }

        return $this->state($userId, $rewarded);
    }

    /**
     * @param  list<array{key: string, cash_cents: int, car_xp: int, xp: int, part: string|null, garage: string|null}>  $rewarded
     */
    private function state(int $userId, array $rewarded): TutorialState
    {
        $rows = $this->rows($userId);
        $total = $this->steps->count();
        $done = $rows->where('state', TutorialProgress::STATE_DONE)->count();

        $active = $rows->first(fn (TutorialProgress $row): bool => $row->state === TutorialProgress::STATE_ACTIVE);
        $step = $active === null ? null : $this->steps->find($active->step_key);

        // A step with no messages is carried out without being shown.
        $current = $step === null || $step['sms'] === [] ? null : [...$step, 'number' => $this->steps->numberOf($step['key']), 'acknowledged' => $active->acknowledged_at !== null];

        $status = match (true) {
            $rows->contains('state', TutorialProgress::STATE_SKIPPED) => TutorialState::SKIPPED,
            $done >= $total => TutorialState::FINISHED,
            default => TutorialState::IN_PROGRESS,
        };

        return new TutorialState($status, $total, $done, $current, $rewarded);
    }

    /** A part of the pack, common, in the inventory of the driver: the slug, or null for a part that the pack does not have. */
    private function givePart(int $userId, string $slug): ?string
    {
        $part = $this->catalog->part($slug);

        if ($part === null) {
            return null;
        }

        Part::query()->create(['user_id' => $userId, 'part_slug' => $slug, 'slot' => $part['slot'], 'rarity' => Rarity::Common->value]);

        return $slug;
    }

    /** Whether a fact of the game holds for the driver now. */
    private function holds(string $condition, int $userId, ?RaceRun $run, ?TutorialProgress $row): bool
    {
        $broken = $run !== null && $run->status === RaceRun::STATUS_STOPPED && $run->stop_reason === 'breakdown';

        return match ($condition) {
            'none' => true,
            'run_started' => $run !== null,
            'driving' => $run !== null && $run->stop_reason !== RaceRun::STOP_PARKED,
            'lap_completed' => $run !== null && $run->laps_done >= 1,
            'fuel_low' => $run !== null && $run->fuel_ml < $this->tankMl($run) * self::LOW_FUEL_SHARE,
            'fuel_restored' => $run !== null && $run->fuel_ml >= $this->tankMl($run) * self::FILLED_FUEL_SHARE,
            'breakdown' => $broken,
            'recovered' => $run !== null && ! $broken,
            'acknowledged' => $row?->acknowledged_at !== null,
            'job_queued' => WorkshopJob::query()->where('user_id', $userId)->exists(),
            'crate_opened' => CrateOpening::query()->where('user_id', $userId)->exists(),
            'travel_started' => Travel::query()->where('user_id', $userId)->exists(),
            default => false,
        };
    }

    private function tankMl(RaceRun $run): int
    {
        return $this->inputs->tankMl($run->car_slug, $run->car?->mountedSlugs() ?? []);
    }
}
