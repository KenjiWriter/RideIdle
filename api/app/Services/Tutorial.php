<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Content\TutorialSteps;
use App\Domain\Run\Rarity;
use App\Domain\Run\SeededWeather;
use App\Domain\Settlement\Settler;
use App\Exceptions\RuleRefusedException;
use App\Models\CrateOpening;
use App\Models\LedgerEntry;
use App\Models\MergeAttempt;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\Travel;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WorkshopJob;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves a driver along the steps of the tutorial (content/rules/tutorial.json).
 *
 * The server is the source of truth: a step starts when the one before it is done and its trigger holds, and it is
 * done when its completion holds, both read from the state of the game (the run, the tank, the breakdown) or from the
 * driver answering the messages. Nothing listens for events: like the settlement of a run, the tutorial is brought up to
 * date whenever somebody looks at it, so it cannot miss what happened while the driver was away. A trigger or a completion
 * is a fact, or a list of facts that must all hold; a fact may take a number or a word after a colon (`cash:450`).
 *
 * A step that has messages is shown before it can be completed, so it ends the pass that started it. What a step pays
 * is posted once, with a key of its own, in the same transaction that marks it done; what it gives to do the step with
 * (its gift) is put in the inventory once, when it starts. The wallet is locked first, as everywhere in the game, so
 * that two requests of one driver go one after another. A step that is optional can be left for later, with no reward.
 */
final class Tutorial
{
    /** A tank under a quarter is low, and one at four fifths is filled. */
    private const LOW_FUEL_SHARE = 0.25;

    private const FILLED_FUEL_SHARE = 0.8;

    /** How many segments of the weather the forecast shows after the one that is now (as the run shows it). */
    private const FORECAST_SEGMENTS = 4;

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
        private readonly PartAffixes $affixes,
        private readonly Rules $rules,
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

    /** The driver leaves the optional step that waits for later: it pays nothing, and the next step goes on. */
    public function decline(User $user): TutorialState
    {
        $userId = (int) $user->getKey();

        DB::transaction(function () use ($userId): void {
            Wallet::query()->where('user_id', $userId)->lockForUpdate()->first();

            $row = TutorialProgress::query()->where('user_id', $userId)->where('state', TutorialProgress::STATE_ACTIVE)->first();
            $step = $row === null ? null : $this->steps->find($row->step_key);

            if ($row === null || ! ($step['optional'] ?? false)) {
                throw new RuleRefusedException('tutorial_step_required');
            }

            $row->update(['state' => TutorialProgress::STATE_DECLINED, 'completed_at' => now()]);
        }, attempts: 3);

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

                if (in_array($row?->state, [TutorialProgress::STATE_DONE, TutorialProgress::STATE_DECLINED], true)) {
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
                if (! $this->holdsAll($step['trigger'], $userId, $run, null)) {
                    break;
                }

                $row = TutorialProgress::query()->create([
                    'user_id' => $userId,
                    'step_key' => $step['key'],
                    'state' => TutorialProgress::STATE_ACTIVE,
                    'started_at' => now(),
                ]);

                // What the step is to be done with is in the inventory before the messages are shown.
                if (isset($step['gift'])) {
                    $this->givePart($userId, $step['gift']['part'], Rarity::fromKey($step['gift']['rarity'] ?? 'common'), (int) ($step['gift']['count'] ?? 1));
                }

                // Shown first: it can be completed on the next look.
                if ($step['sms'] !== []) {
                    break;
                }
            }

            if (! $this->holdsAll($step['completion'], $userId, $run, $row)) {
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
        $done = $rows->whereIn('state', [TutorialProgress::STATE_DONE, TutorialProgress::STATE_DECLINED])->count();

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

    /** Parts of the pack in the inventory of the driver, common unless said otherwise: the slug, or null for a part that the pack does not have. */
    private function givePart(int $userId, string $slug, Rarity $rarity = Rarity::Common, int $count = 1): ?string
    {
        $definition = $this->catalog->part($slug);

        if ($definition === null) {
            return null;
        }

        for ($given = 0; $given < $count; $given++) {
            $part = Part::query()->create(['user_id' => $userId, 'part_slug' => $slug, 'slot' => $definition['slot'], 'rarity' => $rarity->value]);

            $this->affixes->give($part, $rarity);
        }

        return $slug;
    }

    /**
     * Whether every fact of a trigger or a completion holds. The facts about what the driver did (a job done, a car bought) count only what was done since the
     * step started, so that something done before it was asked for does not end it at once.
     *
     * @param  string|list<string>  $conditions
     */
    private function holdsAll(string|array $conditions, int $userId, ?RaceRun $run, ?TutorialProgress $row): bool
    {
        foreach (TutorialSteps::conditions($conditions) as $condition) {
            if (! $this->holds($condition, $userId, $run, $row)) {
                return false;
            }
        }

        return true;
    }

    /** Whether a fact of the game holds for the driver now. */
    private function holds(string $condition, int $userId, ?RaceRun $run, ?TutorialProgress $row): bool
    {
        $broken = $run !== null && $run->status === RaceRun::STATUS_STOPPED && $run->stop_reason === 'breakdown';
        [$condition, $argument] = array_pad(explode(':', $condition, 2), 2, null);
        $since = $row?->started_at;

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
            'travel_done' => Travel::query()->where('user_id', $userId)->where('status', Travel::STATUS_ARRIVED)->exists(),
            'rain_forecast' => $run !== null && $this->rainComes($run),
            'merge_done' => MergeAttempt::query()->where('user_id', $userId)->when($since, fn ($query) => $query->where('created_at', '>=', $since))->exists(),
            'service_done' => $this->jobDone($userId, WorkshopJob::KIND_SERVICE, $since),
            'upgrade_done' => $this->jobDone($userId, WorkshopJob::KIND_UPGRADE, $since),
            'car_bought' => $this->bought($userId, 'exchange', $since),
            'equipment_bought' => $this->bought($userId, 'workshop_gear', $since),
            'garage_bought' => $this->bought($userId, 'garage', $since),
            'email_confirmed' => User::query()->whereKey($userId)->whereNotNull('email_verified_at')->exists(),
            'city_open' => $this->cityOpen($userId),
            'tires_mounted' => $this->tiresMounted($userId, (string) $argument),
            'car_level' => ($this->garage->carOf($userId)?->level ?? 0) >= (int) $argument,
            'cash' => (int) Wallet::query()->where('user_id', $userId)->value('cash_cents') >= (int) $argument * 100,
            'returned' => $this->returnedAfter($userId, (int) $argument),
            default => false,
        };
    }

    /** Whether the wet ground is on the forecast of the track of the run: this segment of the weather and the four after it, as the run shows them. */
    private function rainComes(RaceRun $run): bool
    {
        $weather = $run->config['weather'] ?? null;

        if (! is_array($weather)) {
            return false;
        }

        $seeded = new SeededWeather((string) $weather['seed'], $weather['chances_ppm']);
        $segment = intdiv(RunService::nowMs(), Settler::SEGMENT_MS);

        for ($ahead = 0; $ahead <= self::FORECAST_SEGMENTS; $ahead++) {
            if (($this->rules->weather()[$seeded->at($segment + $ahead)]['ground'] ?? 'dry') === 'wet') {
                return true;
            }
        }

        return false;
    }

    private function jobDone(int $userId, string $kind, ?CarbonInterface $since): bool
    {
        return WorkshopJob::query()->where('user_id', $userId)->where('kind', $kind)->where('status', WorkshopJob::STATUS_DONE)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))->exists();
    }

    /** Whether the driver paid for something of that kind (a posting of the ledger with that reason) since the step started. */
    private function bought(int $userId, string $reason, ?CarbonInterface $since): bool
    {
        return LedgerEntry::query()->where('user_id', $userId)->where('reason', $reason)->where('delta', '<', 0)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))->exists();
    }

    /** Whether there is a city with a track that the level of the driver opens, where the driver has no garage yet. */
    private function cityOpen(int $userId): bool
    {
        $level = $this->rules->playerLevelAt((int) Wallet::query()->where('user_id', $userId)->value('xp'));
        $owned = $this->garages->of($userId)->pluck('region')->all();

        foreach (array_keys($this->rules->regions()) as $region) {
            if (! in_array($region, $owned, true) && $this->rules->cityMinPlayerLevel($region) <= $level) {
                return true;
            }
        }

        return false;
    }

    /** Whether the car that the driver plays stands on tires of that kind. */
    private function tiresMounted(int $userId, string $kind): bool
    {
        $car = $this->garage->carOf($userId);
        $slug = $car?->mountedSlugs()['tires'] ?? null;

        return $slug !== null && ($this->catalog->part($slug)['tire_kind'] ?? null) === $kind;
    }

    /** Whether the last two visits of the driver were at least this many minutes apart, which is a break that ended just now (see `TouchLastSeen`). */
    private function returnedAfter(int $userId, int $minutes): bool
    {
        $user = User::query()->find($userId);

        return $user?->last_seen_at !== null && $user->previous_seen_at !== null && $user->previous_seen_at->diffInMinutes($user->last_seen_at) >= $minutes;
    }

    private function tankMl(RaceRun $run): int
    {
        return $this->inputs->tankMl($run->car_slug, $run->car?->mountedSlugs() ?? []);
    }
}
