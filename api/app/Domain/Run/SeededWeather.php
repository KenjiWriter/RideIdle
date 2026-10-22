<?php

declare(strict_types=1);

namespace App\Domain\Run;

use App\Domain\Settlement\WeatherSchedule;
use InvalidArgumentException;

/**
 * The weather of a region: each 22.5 minute segment draws its weather from the climate seed and the
 * segment number, so it needs no storage and can be computed backwards (settlement) and forwards (forecast).
 */
final readonly class SeededWeather implements WeatherSchedule
{
    /** @param array<string, int> $chancesPpm chance of every weather in ppm, adding up to 1 000 000, checked in this order */
    public function __construct(private string $seed, private array $chancesPpm)
    {
        if ($chancesPpm === [] || array_sum($chancesPpm) !== 1_000_000) {
            throw new InvalidArgumentException('The weather chances must add up to 1 000 000 ppm.');
        }

        foreach ($chancesPpm as $weather => $chance) {
            if ($chance < 0) {
                throw new InvalidArgumentException("The chance of {$weather} cannot be negative.");
            }
        }
    }

    public function at(int $segment): string
    {
        $roll = Rng::roll($this->seed, 'weather', $segment);
        $threshold = 0;

        foreach ($this->chancesPpm as $weather => $chance) {
            $threshold += $chance;

            if ($roll < $threshold) {
                return (string) $weather;
            }
        }

        // Unreachable: the chances add up to one million and a roll is below it.
        return (string) array_key_last($this->chancesPpm);
    }
}
