<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Run\FrozenRun;
use App\Domain\Run\RunFreezer;
use App\Domain\Run\RunInputs;
use App\Domain\Settlement\RunState;
use App\Domain\Settlement\SettlementEvent;
use App\Domain\Settlement\Settler;
use App\Models\RaceRun;
use App\Models\Settlement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Starts runs and settles them in the database.
 *
 * Settling is one transaction: lock the wallet of the driver, then lock the run (always in this order, so two
 * requests cannot wait for each other), read the clock, run the pure settlement, write the settlement with
 * its log, post the money to the ledger with the key settle:{run}:{version}, update the run and bump its version.
 * If anything fails, nothing is written.
 */
final class RunService
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Settler $settler = new Settler(),
        private readonly RunFreezer $freezer = new RunFreezer(),
    ) {
    }

    /** Milliseconds since the Unix epoch from the (testable) application clock. */
    public static function nowMs(): int
    {
        return now()->getTimestampMs();
    }

    /** Starts a run: freezes the configuration with a new seed and puts the cursor at the start time. */
    public function start(int $userId, string $carSlug, string $trackSlug, RunInputs $inputs, int $fuelMl, ?int $nowMs = null): RaceRun
    {
        $seed = bin2hex(random_bytes(16));
        $frozen = $this->freezer->freeze($inputs, $seed, 0);

        return RaceRun::query()->create([
            'user_id' => $userId,
            'car_slug' => $carSlug,
            'track_slug' => $trackSlug,
            'status' => RaceRun::STATUS_RUNNING,
            'cursor_ms' => $nowMs ?? self::nowMs(),
            'laps_done' => 0,
            'fuel_ml' => $fuelMl,
            'break_after_laps' => $frozen->breakAfterLaps,
            'seed' => $seed,
            'config' => $frozen->config,
            'ruleset_version' => $this->rulesetVersion(),
            'state_version' => 0,
        ]);
    }

    /**
     * Settles the run up to `now` (the application clock unless given, which tests and simulations use).
     * Safe to call at any time and any number of times: a second call at the same moment changes nothing.
     */
    public function settle(int $runId, ?int $nowMs = null): Settled
    {
        return $this->locked($runId, $nowMs, fn (RaceRun $run, int $now): Settled => $this->settleLocked($run, $now));
    }

    /**
     * Freezes a new configuration into the run, for example after a part was changed or the service state moved.
     *
     * The run is settled first, so everything up to now is paid with the old configuration. A driving run
     * continues from now: the lap in progress is dropped rather than finished with the new lap time, which
     * would apply the change to time that already passed. The seed stays, so saving the same setup again cannot
     * re-roll the planned breakdown; a new plan needs a repair. A stopped run stays stopped.
     */
    public function reconfigure(int $runId, RunInputs $inputs, ?int $nowMs = null): RaceRun
    {
        return $this->locked($runId, $nowMs, function (RaceRun $run, int $now) use ($inputs): RaceRun {
            $this->settleLocked($run, $now);

            $frozen = $this->freezer->freeze($inputs, $run->seed, $run->laps_done);

            $run->forceFill([
                'config' => $frozen->config,
                'break_after_laps' => $frozen->breakAfterLaps,
                'ruleset_version' => $this->rulesetVersion(),
                'cursor_ms' => $run->status === RaceRun::STATUS_RUNNING ? max($run->cursor_ms, $now) : $run->cursor_ms,
                'state_version' => $run->state_version + 1,
            ])->save();

            return $run;
        });
    }

    /**
     * Settles a run that is already locked (the wallet of its driver first, then the run).
     * Other services that change a run call this before they touch it.
     */
    public function settleLocked(RaceRun $run, int $nowMs): Settled
    {
        $state = new RunState($run->cursor_ms, $run->laps_done, $run->fuel_ml, $run->break_after_laps);
        $config = FrozenRun::configFromArray($run->config);

        $result = $this->settler->settleInChunks($state, $nowMs, $config);

        if ($result->isEmpty()) {
            return new Settled($result, null);
        }

        $settlement = Settlement::query()->create([
            'race_run_id' => $run->id,
            'state_version' => $run->state_version,
            'cursor_from_ms' => $run->cursor_ms,
            'cursor_to_ms' => $result->state->cursorMs,
            'now_ms' => $nowMs,
            'laps' => $result->laps,
            'cash_cents' => $result->cashCents,
            'xp' => $result->xp,
            'stop_reason' => $result->stopReason()?->value,
        ]);

        $settlement->events()->createMany(array_map(
            static fn (SettlementEvent $event, int $index): array => [
                'seq' => $index + 1,
                'type' => $event->type->value,
                'at_ms' => $event->atMs,
                'payload' => $event->payload,
            ],
            $result->events,
            array_keys($result->events),
        ));

        if ($result->cashCents > 0) {
            $this->ledger->post($run->user_id, $result->cashCents, 'settlement', "settle:{$run->id}:{$run->state_version}", $settlement->id);
        }

        $this->ledger->grantXp($run->user_id, $result->xp);

        $stopReason = $result->stopReason();

        $run->forceFill([
            'cursor_ms' => $result->state->cursorMs,
            'laps_done' => $result->state->lapsDone,
            'fuel_ml' => $result->state->fuelMl,
            'status' => $stopReason !== null ? RaceRun::STATUS_STOPPED : $run->status,
            'stop_reason' => $stopReason?->value ?? $run->stop_reason,
            'state_version' => $run->state_version + 1,
        ])->save();

        return new Settled($result, $settlement);
    }

    /**
     * Runs `$work` with the wallet of the driver and then the run locked, always in this order, so two requests
     * cannot wait for each other. The clock is read after the locks: whoever waited for them gets the real time of its turn.
     *
     * @template T
     *
     * @param  callable(RaceRun, int): T  $work
     * @return T
     */
    private function locked(int $runId, ?int $nowMs, callable $work): mixed
    {
        $userId = RaceRun::query()->whereKey($runId)->value('user_id') ?? throw (new ModelNotFoundException())->setModel(RaceRun::class, [$runId]);

        return DB::transaction(function () use ($runId, $userId, $nowMs, $work): mixed {
            $this->ledger->lockWallet((int) $userId);
            $run = RaceRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();

            return $work($run, $nowMs ?? self::nowMs());
        });
    }

    private function rulesetVersion(): string
    {
        return (string) config('ride.ruleset_version');
    }
}
