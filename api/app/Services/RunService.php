<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Run\FrozenRun;
use App\Domain\Run\RunFreezer;
use App\Domain\Run\RunInputs;
use App\Domain\Settlement\EventType;
use App\Domain\Settlement\RunState;
use App\Domain\Settlement\SettlementEvent;
use App\Domain\Settlement\Settler;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\RunAlreadyExistsException;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
    /** Leaves room in the 128 characters of a ledger key for refuel:{run}:{id}. */
    public const MAX_REQUEST_ID_LENGTH = 64;

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
     * Starts the run of a driver who has none. A driver has at most one run for now: a second car on the track
     * would double the income. The check runs under the lock of the wallet, so two requests of one driver
     * arriving together cannot both pass it.
     *
     * @throws RunAlreadyExistsException
     */
    public function startFirstRun(int $userId, string $carSlug, string $trackSlug, RunInputs $inputs, int $fuelMl, ?int $nowMs = null): RaceRun
    {
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $carSlug, $trackSlug, $inputs, $fuelMl, $nowMs): RaceRun {
            $this->ledger->lockWallet($userId);

            $existing = RaceRun::query()->where('user_id', $userId)->value('id');

            if ($existing !== null) {
                throw new RunAlreadyExistsException((int) $existing);
            }

            return $this->start($userId, $carSlug, $trackSlug, $inputs, $fuelMl, $nowMs);
        }, Ledger::DEADLOCK_ATTEMPTS);
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
     *
     * When the new configuration is for another track, `$trackSlug` is saved together with it.
     */
    public function reconfigure(int $runId, RunInputs $inputs, ?int $nowMs = null, ?string $trackSlug = null): RaceRun
    {
        return $this->locked($runId, $nowMs, function (RaceRun $run, int $now) use ($inputs, $trackSlug): RaceRun {
            $this->settleLocked($run, $now);

            $frozen = $this->freezer->freeze($inputs, $run->seed, $run->laps_done);

            $run->forceFill([
                'track_slug' => $trackSlug ?? $run->track_slug,
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
     * Repairs a run that broke down. The run is settled first, so the laps up to the breakdown are paid with
     * the old configuration. Then the configuration is frozen again with a new seed, which plans the next
     * breakdown independently of the one that just happened, and the car drives again from now: the time it stood
     * is not paid.
     *
     * Only a breakdown is repaired. A run that is driving, or that only ran out of fuel, is left as it is, so a
     * repair cannot be used to re-roll the planned breakdown. If the tank is empty as well, the run stays
     * stopped for fuel and a refuel starts it.
     *
     * A repair that costs money (`$costCents` above zero) is paid after the settlement, so what the car earned
     * before it broke down can pay for it, and only when the repair happens. It needs a `$requestId` chosen by
     * the caller for one intention to repair: sending it again returns the first outcome without repairing or
     * charging twice. If the driver cannot pay, nothing at all is changed.
     *
     * @throws InsufficientFundsException when the driver cannot pay
     */
    public function repair(int $runId, RunInputs $inputs, ?int $nowMs = null, int $costCents = 0, ?string $requestId = null): Repaired
    {
        if ($costCents < 0) {
            throw new InvalidArgumentException('The cost of a repair cannot be negative.');
        }

        if ($costCents > 0) {
            self::checkRequestId($requestId, 'A paid repair');
        }

        return $this->locked($runId, $nowMs, function (RaceRun $run, int $now) use ($inputs, $costCents, $requestId): Repaired {
            $key = $costCents > 0 ? "repair:{$run->id}:{$requestId}" : null;

            if ($key !== null && $this->earlierPosting($key, $run) !== null) {
                return new Repaired($run, false, 0, true);
            }

            $this->settleLocked($run, $now);

            if ($run->status !== RaceRun::STATUS_STOPPED || $run->stop_reason !== EventType::Breakdown->value) {
                return new Repaired($run, false);
            }

            if ($key !== null) {
                $this->ledger->post($run->user_id, -$costCents, 'repair', $key);
            }

            $seed = bin2hex(random_bytes(16));
            $frozen = $this->freezer->freeze($inputs, $seed, $run->laps_done);
            $canDrive = $run->fuel_ml >= $frozen->config['fuel_per_lap_ml'];

            $run->forceFill([
                'seed' => $seed,
                'config' => $frozen->config,
                'break_after_laps' => $frozen->breakAfterLaps,
                'ruleset_version' => $this->rulesetVersion(),
                'status' => $canDrive ? RaceRun::STATUS_RUNNING : RaceRun::STATUS_STOPPED,
                'stop_reason' => $canDrive ? null : EventType::FuelEmpty->value,
                'cursor_ms' => max($run->cursor_ms, $now),
                'state_version' => $run->state_version + 1,
            ])->save();

            return new Repaired($run, true, $costCents);
        });
    }

    /**
     * Buys fuel for a run: settles it first (what the car earned so far can pay for the fuel), charges the
     * ledger and fills the tank. Fills the tank when `$addMl` is null, and never more than the tank holds.
     * The price is rounded up to a whole cent.
     *
     * `$requestId` is chosen by the caller for one intention to refuel: sending it again returns the first
     * outcome without charging or filling twice. If the driver cannot pay, nothing at all is changed.
     * A run that was standing (out of fuel) drives again from now, so the time it stood is not paid;
     * a run that broke down stays stopped until it is repaired.
     *
     * @throws InsufficientFundsException when the driver cannot pay
     */
    public function refuel(int $runId, string $requestId, int $tankMl, int $priceCentsPerLiter, ?int $addMl = null, ?int $nowMs = null): Refueled
    {
        self::checkRequestId($requestId, 'A refuel');

        if ($tankMl <= 0 || $priceCentsPerLiter <= 0 || ($addMl !== null && $addMl <= 0)) {
            throw new InvalidArgumentException('The tank, the price and the amount to add must be positive.');
        }

        return $this->locked($runId, $nowMs, function (RaceRun $run, int $now) use ($requestId, $tankMl, $priceCentsPerLiter, $addMl): Refueled {
            $key = "refuel:{$run->id}:{$requestId}";

            if ($this->earlierPosting($key, $run) !== null) {
                return new Refueled($run, 0, 0, true);
            }

            $this->settleLocked($run, $now);

            $room = max(0, $tankMl - $run->fuel_ml);
            $added = min($addMl ?? $room, $room);

            if ($added === 0) {
                return new Refueled($run, 0, 0, false);
            }

            $cost = intdiv($added * $priceCentsPerLiter + 999, 1000);
            $this->ledger->post($run->user_id, -$cost, 'refuel', $key);

            $fuelPerLap = (int) $run->config['fuel_per_lap_ml'];
            $fuel = $run->fuel_ml + $added;
            $wasStanding = $run->status === RaceRun::STATUS_STOPPED || $run->fuel_ml < $fuelPerLap;
            $drivesAgain = $wasStanding && $fuel >= $fuelPerLap && $run->stop_reason !== EventType::Breakdown->value;

            $run->forceFill([
                'fuel_ml' => $fuel,
                'status' => $drivesAgain ? RaceRun::STATUS_RUNNING : $run->status,
                'stop_reason' => $drivesAgain ? null : $run->stop_reason,
                'cursor_ms' => $drivesAgain ? max($run->cursor_ms, $now) : $run->cursor_ms,
                'state_version' => $run->state_version + 1,
            ])->save();

            return new Refueled($run, $added, $cost, false);
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

        // Before the transaction: creating the wallet under a lock deadlocks (see Ledger::ensureWallet).
        $this->ledger->ensureWallet((int) $userId);

        return DB::transaction(function () use ($runId, $userId, $nowMs, $work): mixed {
            $this->ledger->lockWallet((int) $userId);
            $run = RaceRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();

            return $work($run, $nowMs ?? self::nowMs());
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /** The ledger entry of an earlier request with this key, or null. A key of another driver is a conflict. */
    private function earlierPosting(string $key, RaceRun $run): ?LedgerEntry
    {
        $earlier = LedgerEntry::query()->where('idem_key', $key)->first();

        if ($earlier !== null && $earlier->user_id !== $run->user_id) {
            throw new IdempotencyConflictException($key);
        }

        return $earlier;
    }

    private static function checkRequestId(?string $requestId, string $what): void
    {
        if ($requestId === null || $requestId === '' || strlen($requestId) > self::MAX_REQUEST_ID_LENGTH) {
            throw new InvalidArgumentException("{$what} needs a request id of at most ".self::MAX_REQUEST_ID_LENGTH.' characters.');
        }
    }

    private function rulesetVersion(): string
    {
        return (string) config('ride.ruleset_version');
    }
}
