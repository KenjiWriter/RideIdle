<?php

declare(strict_types=1);

namespace App\Domain\Run;

use InvalidArgumentException;

/** What a weather does to a car on a track: multipliers on its statistics and on the rewards. */
final readonly class WeatherEffect
{
    /**
     * @param  array<string, float>  $statMultipliers  for example ['grip' => 0.85] in rain; statistics not listed are unchanged
     * @param  int  $rewardPpm  multiplier of the reward per lap in ppm (930 000 = 0.93)
     * @param  int  $xpPpm  multiplier of the XP per lap in ppm
     */
    public function __construct(
        public array $statMultipliers = [],
        public int $rewardPpm = 1_000_000,
        public int $xpPpm = 1_000_000,
    ) {
        foreach ($statMultipliers as $stat => $multiplier) {
            if ($multiplier <= 0) {
                throw new InvalidArgumentException("The multiplier of {$stat} must be positive.");
            }
        }

        if ($rewardPpm < 0 || $xpPpm < 0) {
            throw new InvalidArgumentException('Reward multipliers cannot be negative.');
        }
    }

    public function multiplierFor(string $stat): float
    {
        return $this->statMultipliers[$stat] ?? 1.0;
    }
}
