<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * A kind of player the economy is simulated for: how often it comes back, how long its cars drive in a day, and how
 * much it gets out of an hour of driving (tournaments, courses and parts put together on the spot).
 */
final readonly class PlayerType
{
    /** @param list<float> $fleet how many cars drive at once, at each rung of the ladder */
    public function __construct(
        public string $key,
        public string $name,
        public int $visitsPerDay,
        public float $maxHoursPerDay,
        public float $activity,
        public array $fleet,
    ) {}

    /**
     * The hours of driving in a day: every visit pays for a tank, which lasts as long as the offline window of the car,
     * and the day has only so many hours.
     */
    public function hoursPerDay(float $offlineWindowHours): float
    {
        return min($this->maxHoursPerDay, $this->visitsPerDay * $offlineWindowHours);
    }

    /** How many cars drive at once at this rung: the last entry holds for every rung after it. */
    public function fleetAt(int $rung): float
    {
        return $this->fleet[$rung] ?? (float) end($this->fleet);
    }
}
