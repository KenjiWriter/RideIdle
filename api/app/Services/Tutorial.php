<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\TutorialSteps;
use App\Domain\Run\Rarity;
use App\Exceptions\RuleRefusedException;
use App\Models\Part;
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
    public function __construct(
        private readonly TutorialSteps $steps,
        private readonly RunService $runs,
        private readonly Workshop $workshop,
        private readonly DriverFacts $facts,
        private readonly Ledger $ledger,
        private readonly Garage $garage,
        private readonly RewardPayer $payer,
        private readonly Catalog $catalog,
        private readonly CityGarages $garages,
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
                if (! $this->facts->holdsAll($step['trigger'], $userId, $run, null)) {
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
                    $this->payer->givePart($userId, $step['gift']['part'], Rarity::fromKey($step['gift']['rarity'] ?? 'common'), (int) ($step['gift']['count'] ?? 1));
                }

                // Shown first: it can be completed on the next look.
                if ($step['sms'] !== []) {
                    break;
                }
            }

            if (! $this->facts->holdsAll($step['completion'], $userId, $run, $row)) {
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
            $part = isset($step['reward']['part']) ? $this->payer->givePart($userId, $step['reward']['part']) : null;
            $garage = isset($step['reward']['garage']) && $this->garages->grant($userId, $step['reward']['garage']) ? $step['reward']['garage'] : null;

            // The experience goes to the car the driver has: with no car there is nothing to give it to.
            $carXp = $this->payer->awardCarXp($userId, $carXp);

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

        return new TutorialState($status, $total, $done, $current, $rewarded, $status === TutorialState::IN_PROGRESS ? $this->goal($rows) : null);
    }

    /**
     * The goal in front of the driver: the first step that is not passed, whether it has started (its row is active) or still waits for its trigger, with what it
     * will pay, so that the driver always knows what to do next and what it is worth.
     *
     * @param  Collection<string, TutorialProgress>  $rows
     * @return array<string, mixed>|null
     */
    private function goal(Collection $rows): ?array
    {
        foreach ($this->steps->all() as $step) {
            $row = $rows->get($step['key']);

            if ($row !== null && $row->state !== TutorialProgress::STATE_ACTIVE) {
                continue;
            }

            $reward = $step['reward'];

            return [
                ...$step,
                'number' => $this->steps->numberOf($step['key']),
                'waits' => $row === null,
                'reward_part_name' => isset($reward['part']) ? $this->catalog->part($reward['part'])['name'] ?? null : null,
                'reward_garage_name' => isset($reward['garage']) ? $this->rules->region($reward['garage'])['name'] ?? null : null,
            ];
        }

        return null;
    }
}
