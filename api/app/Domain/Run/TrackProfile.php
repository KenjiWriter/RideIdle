<?php

declare(strict_types=1);

namespace App\Domain\Run;

use InvalidArgumentException;

/**
 * The demand profile of a track: which statistics matter and how much, and what a lap pays.
 * perf = sum of weight * clamp(stat / reference, 0.2, 1.6), lap time = base time / perf ^ 1.15.
 */
final readonly class TrackProfile
{
    /**
     * @param  array<string, float>  $weights  importance of every statistic, adding up to 1
     * @param  array<string, float>  $references  the value of a statistic that counts as "normal" on this track
     * @param  int  $baseLapMs  lap time of a car with perf 1
     * @param  int  $fuelPerLapMl  fuel used per lap by a car with a fuel use multiplier of 1
     * @param  int  $rewardCentsPerLap  reward per lap in dry weather
     * @param  int  $xpPerLap  XP per lap in dry weather
     */
    public function __construct(
        public array $weights,
        public array $references,
        public int $baseLapMs,
        public int $fuelPerLapMl,
        public int $rewardCentsPerLap,
        public int $xpPerLap,
    ) {
        if ($weights === []) {
            throw new InvalidArgumentException('A track needs at least one weighted statistic.');
        }

        foreach ($weights as $stat => $weight) {
            if ($weight <= 0) {
                throw new InvalidArgumentException("The weight of {$stat} must be positive.");
            }

            if (! isset($references[$stat]) || $references[$stat] <= 0) {
                throw new InvalidArgumentException("The track needs a positive reference for {$stat}.");
            }
        }

        if (abs(array_sum($weights) - 1.0) > 1e-9) {
            throw new InvalidArgumentException('The weights must add up to 1.');
        }

        if ($baseLapMs <= 0 || $fuelPerLapMl <= 0) {
            throw new InvalidArgumentException('The lap time and the fuel use must be positive.');
        }

        if ($rewardCentsPerLap < 0 || $xpPerLap < 0) {
            throw new InvalidArgumentException('Rewards cannot be negative.');
        }
    }
}
