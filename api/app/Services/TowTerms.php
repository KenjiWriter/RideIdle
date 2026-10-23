<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Domain\Settlement\EventType;
use App\Models\RaceRun;

/**
 * When a stopped car is towed and what the tow does.
 *
 * A car that stands does not earn, so a driver who cannot pay to get it going would never get the money. The tow is
 * the way out: free, but the car waits for it, and if the tank is empty it leaves with a little fuel. It is only for
 * a driver who really cannot pay, which is decided here and nowhere else, so what the screen offers is what the
 * server accepts.
 */
final readonly class TowTerms
{
    public function __construct(
        public int $repairCostCents,
        public int $fuelPriceCentsPerLiter,
        /** How long the car waits before it drives again. */
        public int $delayMs,
        /** Laps of fuel left in a tank that was too empty for a lap. */
        public int $fuelLaps,
    ) {}

    public static function fromRules(Rules $rules): self
    {
        return new self($rules->repairCostCents(), $rules->fuelPriceCentsPerLiter(), $rules->towDelayMs(), $rules->towFuelLaps());
    }

    /**
     * What it takes to get the stopped car going by ordinary means: the repair when it broke down, and the fuel
     * for one lap when the tank is too empty for it. Fuel is bought in whole liters, so it is counted in whole
     * liters. Null for a car that is not stopped.
     */
    public function restartCostCents(RaceRun $run): ?int
    {
        // A car that stands in the workshop or on the road is waiting for the mechanic or for the end of the journey, not for the driver:
        // there is nothing to tow.
        if ($run->status !== RaceRun::STATUS_STOPPED || in_array($run->stop_reason, RaceRun::SERVICE_STOPS, true)) {
            return null;
        }

        $repair = $run->stop_reason === EventType::Breakdown->value ? $this->repairCostCents : 0;
        $missingMl = max(0, (int) $run->config['fuel_per_lap_ml'] - $run->fuel_ml);

        return $repair + (int) ceil($missingMl / 1000) * $this->fuelPriceCentsPerLiter;
    }

    /** The driver cannot pay what it takes to restart the car, so the tow is theirs to ask for. */
    public function isDue(RaceRun $run, int $cashCents): bool
    {
        $cost = $this->restartCostCents($run);

        return $cost !== null && $cashCents < $cost;
    }
}
