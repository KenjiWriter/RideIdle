<?php

declare(strict_types=1);

namespace App\Domain\Run;

use InvalidArgumentException;

/** Everything the freezing of a run depends on. */
final readonly class RunInputs
{
    /**
     * @param  array<string, int>  $weatherChancesPpm  chance of every weather of the region, adding up to 1 000 000
     * @param  array<string, WeatherEffect>  $weatherEffects  effect per weather; a weather without an entry changes nothing
     * @param  string  $climateSeed  seed of the region, which makes the weather of every segment reproducible
     * @param  int  $breakChancePerLapPpm  chance that the car breaks down after a given lap, from its condition (0 = never)
     * @param  int  $nightLapPpm  how much of its time a lap takes at night: 1 000 000 on a track that is lit, more on one that is not
     * @param  FieldProfile|null  $field  the bots that the car races against and what the place among them pays, null for a car that races alone
     */
    public function __construct(
        public CarStats $car,
        public TrackProfile $track,
        public array $weatherChancesPpm,
        public array $weatherEffects,
        public string $climateSeed,
        public int $breakChancePerLapPpm = 0,
        public int $nightLapPpm = 1_000_000,
        public ?FieldProfile $field = null,
    ) {
        if ($weatherChancesPpm === [] || array_sum($weatherChancesPpm) !== 1_000_000) {
            throw new InvalidArgumentException('The weather chances must add up to 1 000 000 ppm.');
        }

        if ($climateSeed === '') {
            throw new InvalidArgumentException('The climate seed cannot be empty.');
        }

        if ($nightLapPpm < 1_000_000) {
            throw new InvalidArgumentException('A lap cannot take less at night than by day.');
        }

        if ($breakChancePerLapPpm < 0 || $breakChancePerLapPpm > 1_000_000) {
            throw new InvalidArgumentException('The breakdown chance must be between 0 and 1 000 000 ppm.');
        }
    }

    public function effectFor(string $weather): WeatherEffect
    {
        return $this->weatherEffects[$weather] ?? new WeatherEffect;
    }
}
