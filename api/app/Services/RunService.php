<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\ContentSource;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\FrozenRun;
use App\Domain\Run\RunFreezer;
use App\Domain\Run\RunInputs;
use App\Domain\Settlement\EventType;
use App\Domain\Settlement\RunState;
use App\Domain\Settlement\SettlementEvent;
use App\Domain\Settlement\SettlementResult;
use App\Domain\Settlement\Settler;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\RunAlreadyExistsException;
use App\Models\Car;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\User;
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

    /** How many times one settlement freezes the configuration again at most: a stage of wear is thousands of laps, so a long absence needs a few dozen. */
    private const MAX_PASSES = 200;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Settler $settler = new Settler,
        private readonly RunFreezer $freezer = new RunFreezer,
        private readonly ?Rules $rules = null,
        private readonly ?CarProgress $progress = null,
        private readonly ?TutorialScript $script = null,
        private ?CarWear $carWear = null,
    ) {}

    /** Milliseconds since the Unix epoch from the (testable) application clock. */
    public static function nowMs(): int
    {
        return now()->getTimestampMs();
    }

    /**
     * Starts a run: freezes the configuration with a new seed and puts the cursor at the start time. The run drives
     * `$car`, or a new car of the driver at the level a new car has when none is given.
     */
    public function start(int $userId, string $carSlug, string $trackSlug, RunInputs $inputs, int $fuelMl, ?int $nowMs = null, ?Car $car = null, bool $parked = false): RaceRun
    {
        $seed = bin2hex(random_bytes(16));
        $frozen = $this->freezer->freeze($inputs, $seed, 0);

        $rules = $this->rules ?? Rules::fromConfig();

        // The first run of a driver is arranged for the tutorial: a small tank, which is low in a few minutes, and a breakdown a few laps in.
        $breakAfter = $this->script?->breakdownLap($userId, null, 0) ?? $frozen->breakAfterLaps;
        $fuelMl = min($fuelMl, $this->script?->firstRunFuelMl($userId) ?? $fuelMl);

        $car ??= Car::query()->create([
            'user_id' => $userId,
            'vehicle_slug' => $carSlug,
            'level' => $rules->carStartLevel(),
            'xp' => $rules->carXpToReach($rules->carStartLevel()),
            // A wreck is worth what the yard asks for it, which is what a sale pays a share of.
            'value_cents' => $rules->wreckPriceCents($carSlug),
            // It has driven what the board of the wrecks says, and its first service is due an interval after that.
            'mileage_m' => $rules->wreckMileageKm($carSlug) * 1000,
            'serviced_at_m' => $rules->wreckMileageKm($carSlug) * 1000,
        ]);

        return RaceRun::query()->create([
            'user_id' => $userId,
            'car_id' => $car->id,
            'car_slug' => $carSlug,
            'track_slug' => $trackSlug,
            'status' => $parked ? RaceRun::STATUS_STOPPED : RaceRun::STATUS_RUNNING,
            'stop_reason' => $parked ? RaceRun::STOP_PARKED : null,
            'cursor_ms' => $nowMs ?? self::nowMs(),
            'laps_done' => 0,
            'fuel_ml' => $fuelMl,
            'break_after_laps' => $breakAfter,
            'recompute_after_laps' => $frozen->recomputeAfterLaps,
            'seed' => $seed,
            'config' => $frozen->config,
            'ruleset_version' => $this->rulesetVersion(),
            'state_version' => 0,
        ]);
    }

    /**
     * Starts the run of a driver who has none. A driver has at most one run for now: a second car on the track
     * would double the income. The check runs under the lock of the wallet, so two requests of one driver
     * arriving together cannot both pass it. A parked run waits in the garage for `begin`.
     *
     * @throws RunAlreadyExistsException
     */
    public function startFirstRun(int $userId, string $carSlug, string $trackSlug, RunInputs $inputs, int $fuelMl, ?int $nowMs = null, bool $parked = false): RaceRun
    {
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $carSlug, $trackSlug, $inputs, $fuelMl, $nowMs, $parked): RaceRun {
            $this->ledger->lockWallet($userId);

            $existing = RaceRun::query()->where('user_id', $userId)->value('id');

            if ($existing !== null) {
                throw new RunAlreadyExistsException((int) $existing);
            }

            return $this->start($userId, $carSlug, $trackSlug, $inputs, $fuelMl, $nowMs, parked: $parked);
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * Sends out a car that waits in the garage: it drives from now on, and nothing was earned while it stood. It leaves with a head start
     * (`start.head_start_ms` of the rules, never more than its shortest lap less a second), so that its first lap is short: the tutorial waits
     * for it, and a newcomer who has just pressed Start should not stare at a lap of a minute and a quarter. A car that does not wait (it drives
     * already, or it stands for another reason) is left as it is, so pressing Start twice is the same as pressing it once.
     */
    public function begin(int $runId, ?int $nowMs = null): RaceRun
    {
        return $this->locked($runId, $nowMs, function (RaceRun $run, int $now): RaceRun {
            if ($run->stop_reason !== RaceRun::STOP_PARKED) {
                return $run;
            }

            $shortestLapMs = min(array_map('intval', $run->config['lap_ms']));
            $headStartMs = max(0, min(($this->rules ?? Rules::fromConfig())->startHeadStartMs(), $shortestLapMs - 1_000));

            $run->forceFill([
                'status' => RaceRun::STATUS_RUNNING,
                'stop_reason' => null,
                'cursor_ms' => max($run->cursor_ms, $now) - $headStartMs,
                'state_version' => $run->state_version + 1,
            ])->save();

            return $run;
        });
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
                'break_after_laps' => $this->script?->breakdownLap($run->user_id, $run->id, $run->laps_done) ?? $frozen->breakAfterLaps,
                'recompute_after_laps' => $frozen->recomputeAfterLaps,
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
                'recompute_after_laps' => $frozen->recomputeAfterLaps,
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
     * Tows a stopped car for a driver who cannot pay to get it going (see TowTerms). The run is settled first, so
     * what the car earned before it stopped counts: a driver who can pay for the repair after all is not towed.
     *
     * The tow is free. A car that broke down is repaired as by `repair` (a new seed, so a new plan of the next
     * breakdown), a car with a tank too empty for a lap gets the fuel of the terms, and the car drives again after
     * the wait, which is a cursor in the future that settlement simply does not reach before its time.
     * A car that is not stopped, or whose driver can pay, is left as it is, so asking twice tows once.
     */
    public function tow(int $runId, RunInputs $inputs, TowTerms $terms, ?int $nowMs = null): Towed
    {
        return $this->locked($runId, $nowMs, function (RaceRun $run, int $now) use ($inputs, $terms): Towed {
            $this->settleLocked($run, $now);

            if (! $terms->isDue($run, $this->ledger->balance($run->user_id))) {
                return new Towed($run, false);
            }

            $changes = [];
            $config = $run->config;

            if ($run->stop_reason === EventType::Breakdown->value) {
                $seed = bin2hex(random_bytes(16));
                $frozen = $this->freezer->freeze($inputs, $seed, $run->laps_done);

                $changes = [
                    'seed' => $seed,
                    'config' => $frozen->config,
                    'break_after_laps' => $frozen->breakAfterLaps,
                    'recompute_after_laps' => $frozen->recomputeAfterLaps,
                    'ruleset_version' => $this->rulesetVersion(),
                ];
                $config = $frozen->config;
            }

            $fuelPerLap = (int) $config['fuel_per_lap_ml'];

            $run->forceFill([
                ...$changes,
                'fuel_ml' => $run->fuel_ml < $fuelPerLap ? $terms->fuelLaps * $fuelPerLap : $run->fuel_ml,
                'status' => RaceRun::STATUS_RUNNING,
                'stop_reason' => null,
                'cursor_ms' => $now + $terms->delayMs,
                'state_version' => $run->state_version + 1,
            ])->save();

            return new Towed($run, true);
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
            $drivesAgain = $wasStanding && $fuel >= $fuelPerLap
                && ! in_array($run->stop_reason, [EventType::Breakdown->value, ...RaceRun::SERVICE_STOPS], true);

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
     * The car goes into the workshop at `$atMs`, with the run already locked (see Workshop): what it earned until then is
     * paid with the old configuration, and then it stands, stopped for the workshop, until the last job on it is done.
     * A car that stands for another reason already (a breakdown, an empty tank) keeps that reason.
     */
    public function enterWorkshop(RaceRun $run, int $atMs): void
    {
        if ($run->status === RaceRun::STATUS_STOPPED) {
            return;
        }

        $this->settleLocked($run, $atMs);

        // The settlement may have stopped it (the tank ran out, it broke down) before the time came.
        if ($run->status === RaceRun::STATUS_STOPPED) {
            return;
        }

        $run->forceFill([
            'status' => RaceRun::STATUS_STOPPED,
            'stop_reason' => RaceRun::STOP_WORKSHOP,
            'state_version' => $run->state_version + 1,
        ])->save();
    }

    /**
     * The last job on the car is done at `$atMs`, with the run already locked: the configuration is frozen again with the
     * parts that are on it now (same seed, so the planned breakdown does not move) and a car that stood for the workshop
     * drives again from `$atMs`. A car that stands for another reason is only reconfigured.
     */
    public function leaveWorkshop(RaceRun $run, RunInputs $inputs, int $atMs): void
    {
        $frozen = $this->freezer->freeze($inputs, $run->seed, $run->laps_done);
        $resumes = $run->stop_reason === RaceRun::STOP_WORKSHOP;

        $run->forceFill([
            'config' => $frozen->config,
            'break_after_laps' => $frozen->breakAfterLaps,
            'recompute_after_laps' => $frozen->recomputeAfterLaps,
            'ruleset_version' => $this->rulesetVersion(),
            'status' => $resumes ? RaceRun::STATUS_RUNNING : $run->status,
            'stop_reason' => $resumes ? null : $run->stop_reason,
            'cursor_ms' => $resumes ? max($run->cursor_ms, $atMs) : $run->cursor_ms,
            'state_version' => $run->state_version + 1,
        ])->save();
    }

    /**
     * The car leaves for another city, with the run already locked and settled to this moment: it takes the fuel of the road out of the tank
     * and stands, stopped for the travel, until it arrives. What it earned until now is paid by the settlement before this is called.
     */
    public function depart(RaceRun $run, int $fuelMl): void
    {
        $run->forceFill([
            'status' => RaceRun::STATUS_STOPPED,
            'stop_reason' => RaceRun::STOP_TRAVEL,
            'fuel_ml' => $run->fuel_ml - $fuelMl,
            'state_version' => $run->state_version + 1,
        ])->save();
    }

    /**
     * The car arrives at `$atMs`, with the run already locked: the configuration is frozen again for the track of the city it is in
     * (same seed, so the planned breakdown does not move) and it drives from `$atMs`. A car that does not stand for the travel is only
     * reconfigured, and keeps its stop.
     */
    public function arrive(RaceRun $run, RunInputs $inputs, string $trackSlug, int $atMs): void
    {
        $frozen = $this->freezer->freeze($inputs, $run->seed, $run->laps_done);
        $resumes = $run->stop_reason === RaceRun::STOP_TRAVEL;

        $run->forceFill([
            'config' => $frozen->config,
            'break_after_laps' => $frozen->breakAfterLaps,
            'recompute_after_laps' => $frozen->recomputeAfterLaps,
            'track_slug' => $trackSlug,
            'ruleset_version' => $this->rulesetVersion(),
            'status' => $resumes ? RaceRun::STATUS_RUNNING : $run->status,
            'stop_reason' => $resumes ? null : $run->stop_reason,
            'cursor_ms' => $resumes ? max($run->cursor_ms, $atMs) : $run->cursor_ms,
            'state_version' => $run->state_version + 1,
        ])->save();
    }

    /**
     * Settles a run that is already locked (the wallet of its driver first, then the run).
     * Other services that change a run call this before they touch it.
     */
    public function settleLocked(RaceRun $run, int $nowMs): Settled
    {
        $settled = $this->settleOnce($run, $nowMs);

        // A part that has worn to its next stage, or a service that has moved on, changes the car at a lap that was known when the configuration was frozen:
        // the settlement stops there, the configuration is frozen again with what the car is now (the same seed), and the rest is settled with it. Wear is a
        // function of the laps, so this gives what one pass would if the car were looked at in between, and the passes are few (a stage is thousands of laps).
        for ($pass = 0; $pass < self::MAX_PASSES && $this->mustFreezeAgain($run); $pass++) {
            $this->freezeAgain($run);
            $settled = self::together($settled, $this->settleOnce($run, $nowMs));
        }

        // A run that was frozen with other content than the one that is live now (a new pack was made live) has been settled up to now with the lap it was
        // frozen with, which is what it was driving; from now on it drives the new content.
        if ($run->ruleset_version !== $this->rulesetVersion()) {
            $this->freezeForNewContent($run);
        }

        return $settled;
    }

    /**
     * Freezes a run again for the content that is live now. If the content cannot make the run (the car or the track is not in it any more), the run keeps
     * the configuration it has, which is all that settling needs, and is marked as seen so that it is not tried at every settlement; the problem is reported.
     */
    private function freezeForNewContent(RaceRun $run): void
    {
        try {
            $this->freezeAgain($run);
        } catch (InvalidArgumentException $problem) {
            report($problem);
        }

        if ($run->ruleset_version !== $this->rulesetVersion()) {
            $run->forceFill(['ruleset_version' => $this->rulesetVersion()])->save();
        }
    }

    /** Whether the run has reached the lap at which its configuration has to be made again. */
    private function mustFreezeAgain(RaceRun $run): bool
    {
        return $run->car_id !== null && $run->recompute_after_laps !== null && $run->laps_done >= $run->recompute_after_laps;
    }

    /**
     * Freezes the configuration of the run again with the car as it is now (the parts with their wear, the odometer, the service), without touching the
     * seed. A car that stands because it broke down keeps the lap of its breakdown: the new plan is made when it is repaired.
     */
    private function freezeAgain(RaceRun $run): void
    {
        $inputs = $this->inputsToFreeze($run);

        if ($inputs === null) {
            $run->forceFill(['recompute_after_laps' => null])->save();

            return;
        }

        $frozen = $this->freezer->freeze($inputs, $run->seed, $run->laps_done);

        $run->forceFill([
            'config' => $frozen->config,
            'break_after_laps' => $run->status === RaceRun::STATUS_STOPPED ? $run->break_after_laps : ($this->script?->breakdownLap($run->user_id, $run->id, $run->laps_done) ?? $frozen->breakAfterLaps),
            'recompute_after_laps' => $frozen->recomputeAfterLaps,
            'ruleset_version' => $this->rulesetVersion(),
            'state_version' => $run->state_version + 1,
        ])->save();
    }

    /** What the run is frozen from now: its car as it is (the parts with their wear, the odometer, the service), or the body of its car when it has none. Null when its car is gone. */
    private function inputsToFreeze(RaceRun $run): ?RunInputs
    {
        $factory = app(RunInputsFactory::class);

        if ($run->car_id === null) {
            return $factory->make($run->car_slug, $run->track_slug);
        }

        $car = Car::query()->find($run->car_id);

        return $car === null ? null : $factory->makeForCar($car, $run->track_slug);
    }

    /** What two passes of settling one run come to: the laps and the cash added up, the events in order, and the state of the last pass. */
    private static function together(Settled $first, Settled $second): Settled
    {
        $events = [...$first->result->events, ...$second->result->events];
        usort($events, static fn (SettlementEvent $a, SettlementEvent $b): int => [$a->atMs, $a->priority()] <=> [$b->atMs, $b->priority()]);

        return new Settled(
            new SettlementResult($second->result->state, $events, $first->result->laps + $second->result->laps, $first->result->cashCents + $second->result->cashCents, $first->result->xp + $second->result->xp),
            $second->settlement ?? $first->settlement,
            [...$first->all(), ...$second->all()],
        );
    }

    /** One pass of settling: up to now, or up to the lap at which the configuration has to be made again, whichever comes first. */
    private function settleOnce(RaceRun $run, int $nowMs): Settled
    {
        $state = new RunState($run->cursor_ms, $run->laps_done, $run->fuel_ml, $run->break_after_laps, $run->recompute_after_laps);
        $config = FrozenRun::configFromArray($run->config);

        // A car in the workshop earns nothing: for settlement no time passes until it leaves, and then it drives from the
        // moment it leaves. (A breakdown and an empty tank stop the run inside the settlement; this stop is not an event of it.)
        $until = in_array($run->stop_reason, RaceRun::SERVICE_STOPS, true) ? $run->cursor_ms : $nowMs;

        $result = $this->settler->settleInChunks($state, $until, $config);

        if ($result->isEmpty()) {
            return new Settled($result, null);
        }

        // The driver is levelled by the car that they play; the others earn cash and level themselves (`player.other_cars_xp_ppm`).
        $xp = $this->experienceForDriver($run, $result->xp);

        $settlement = Settlement::query()->create([
            'race_run_id' => $run->id,
            'state_version' => $run->state_version,
            'cursor_from_ms' => $run->cursor_ms,
            'cursor_to_ms' => $result->state->cursorMs,
            'now_ms' => $nowMs,
            'laps' => $result->laps,
            'cash_cents' => $result->cashCents,
            'xp' => $xp,
            'stop_reason' => $result->stopReason()?->value,
        ]);

        // The car has driven the laps: its odometer and its parts are put forward, and what that makes of the log is told (the part that broke down, the service).
        $events = array_values(array_filter($this->afterDriving($run, $result), static fn (SettlementEvent $event): bool => $event->type !== EventType::Recompute));

        $settlement->events()->createMany(array_map(
            static fn (SettlementEvent $event, int $index): array => [
                'seq' => $index + 1,
                'type' => $event->type->value,
                'at_ms' => $event->atMs,
                'payload' => $event->payload,
            ],
            $events,
            array_keys($events),
        ));

        if ($result->cashCents > 0) {
            $this->ledger->post($run->user_id, $result->cashCents, 'settlement', "settle:{$run->id}:{$run->state_version}", $settlement->id);
        }

        $this->ledger->grantXp($run->user_id, $xp);

        $stopReason = $result->stopReason();

        $run->forceFill([
            'cursor_ms' => $result->state->cursorMs,
            'laps_done' => $result->state->lapsDone,
            'fuel_ml' => $result->state->fuelMl,
            'status' => $stopReason !== null ? RaceRun::STATUS_STOPPED : $run->status,
            'stop_reason' => $stopReason?->value ?? $run->stop_reason,
            'state_version' => $run->state_version + 1,
        ])->save();

        // The car levels on the laps it drives, so the experience goes with the settlement of them.
        if ($result->laps > 0 && $run->car_id !== null) {
            $rules = $this->rules ?? Rules::fromConfig();

            ($this->progress ?? new CarProgress($rules))->award($run->car_id, $result->laps * $rules->carXpPerLap());
        }

        return new Settled($result, $settlement);
    }

    /**
     * Puts the odometer and the wear of the parts of the car forward by the laps of a settlement, and tells what that makes of its log: the breakdown says
     * which part it was, and the car that drove past the moment its service was due says so.
     *
     * @return list<SettlementEvent>
     */
    private function afterDriving(RaceRun $run, SettlementResult $result): array
    {
        $events = $result->events;
        $wear = $run->config['wear'] ?? null;
        $car = $wear === null || $run->car_id === null ? null : Car::query()->find($run->car_id);

        if ($car === null || $result->laps <= 0) {
            return $events;
        }

        $before = (int) $car->mileage_m;
        $after = $before + $result->laps * (int) $wear['length_m'];
        $dueAt = (int) $car->serviced_at_m + ($this->rules ?? Rules::fromConfig())->service()['every_km'] * 1000;

        $this->wear()->apply($car->id, $run->config, $result->laps);

        if ($before < $dueAt && $after >= $dueAt) {
            $events[] = SettlementEvent::serviceDue($result->state->cursorMs, intdiv($after, 1000));
        }

        foreach ($events as $index => $event) {
            if ($event->type === EventType::Breakdown && ($part = $this->wear()->breakdownPart($car->id, $run->seed, $result->state->lapsDone)) !== null) {
                $events[$index] = $event->withPayload(['part' => $part]);
            }
        }

        return $events;
    }

    private function wear(): CarWear
    {
        return $this->carWear ??= new CarWear;
    }

    /**
     * The experience of a settlement that goes to the driver: all of it when the run is of the car that the driver plays (the one they selected, or the
     * first they have), and the share of the rules (`player.other_cars_xp_ppm`) when it is of another car. A run with no car counts as played.
     */
    private function experienceForDriver(RaceRun $run, int $xp): int
    {
        if ($xp === 0 || $run->car_id === null) {
            return $xp;
        }

        $selected = User::query()->whereKey($run->user_id)->value('selected_car_id');
        $cars = Car::query()->where('user_id', $run->user_id);
        $played = $selected !== null && (clone $cars)->whereKey($selected)->exists() ? (int) $selected : (int) (clone $cars)->orderBy('id')->value('id');

        if ($run->car_id === $played) {
            return $xp;
        }

        return intdiv($xp * ($this->rules ?? Rules::fromConfig())->otherCarsXpPpm(), 1_000_000);
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
        $userId = RaceRun::query()->whereKey($runId)->value('user_id') ?? throw (new ModelNotFoundException)->setModel(RaceRun::class, [$runId]);

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

    /** The version of the content the runs are frozen with: the number of the pack that is live, or the one of the configuration when the content is read from files. */
    private function rulesetVersion(): string
    {
        return app(ContentSource::class)->rulesetVersion();
    }
}
