<?php

declare(strict_types=1);

namespace App\Domain\Run;

use InvalidArgumentException;

/** The statistics of a car as they are when a run starts: parts, rarity, upgrades and condition already applied. */
final readonly class CarStats
{
    /**
     * @param  array<string, float>  $stats  for example ['power' => 118, 'grip' => 61, 'handling' => 70]
     * @param  float  $fuelUseMultiplier  1.0 is the track's base fuel use, a thirstier car has more
     */
    public function __construct(public array $stats, public float $fuelUseMultiplier = 1.0)
    {
        foreach ($stats as $stat => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("The statistic {$stat} cannot be negative.");
            }
        }

        if ($fuelUseMultiplier <= 0) {
            throw new InvalidArgumentException('The fuel use multiplier must be positive.');
        }
    }

    public function get(string $stat): float
    {
        return $this->stats[$stat] ?? throw new InvalidArgumentException("The car has no statistic {$stat}.");
    }
}
