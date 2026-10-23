<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\RunInputsFactory;
use App\Content\TutorialSteps;
use App\Models\RaceRun;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\Wallet;
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
    ) {}

    /** Brings the tutorial of the driver up to date, which settles the run first, and tells where it stands. */
    public function evaluate(User $user): TutorialState
    {
        $userId = (int) $user->getKey();

        $this->workshop->advance($userId);
        $run = RaceRun::query()->where('user_id', $userId)->orderByDesc('id')->first();

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
                if (! $this->holds($step['trigger'], $run, null)) {
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

            if (! $this->holds($step['completion'], $run, $row)) {
                break;
            }

            $row->update(['state' => TutorialProgress::STATE_DONE, 'completed_at' => now()]);

            $cents = (int) ($step['reward']['cash_cents'] ?? 0);

            if ($cents > 0) {
                $this->ledger->post($userId, $cents, 'tutorial', "tutorial:{$userId}:{$step['key']}");
                $rewarded[] = ['key' => $step['key'], 'cash_cents' => $cents];
            }
        }

        return $this->state($userId, $rewarded);
    }

    /**
     * @param  list<array{key: string, cash_cents: int}>  $rewarded
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

    /** Whether a fact of the game holds for the driver now. */
    private function holds(string $condition, ?RaceRun $run, ?TutorialProgress $row): bool
    {
        $broken = $run !== null && $run->status === RaceRun::STATUS_STOPPED && $run->stop_reason === 'breakdown';

        return match ($condition) {
            'none' => true,
            'run_started' => $run !== null,
            'lap_completed' => $run !== null && $run->laps_done >= 1,
            'fuel_low' => $run !== null && $run->fuel_ml < $this->tankMl($run) * self::LOW_FUEL_SHARE,
            'fuel_restored' => $run !== null && $run->fuel_ml >= $this->tankMl($run) * self::FILLED_FUEL_SHARE,
            'breakdown' => $broken,
            'recovered' => $run !== null && ! $broken,
            'acknowledged' => $row?->acknowledged_at !== null,
            default => false,
        };
    }

    private function tankMl(RaceRun $run): int
    {
        return $this->inputs->tankMl($run->car_slug, $run->car?->mountedSlugs() ?? []);
    }
}
