<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use InvalidArgumentException;

/**
 * The frozen configuration of a run: lap time, reward and XP per weather and the
 * fuel used per lap. Values are computed once when the run starts or when the
 * car, a part, the track or the service state changes, and are never
 * recomputed during settlement.
 */
final readonly class RunConfig
{
    /**
     * @param  array<string, int>  $lapMs  lap time in milliseconds per weather
     * @param  array<string, int>  $lapCashCents  reward per lap in cents per weather
     * @param  array<string, int>  $lapXp  XP per lap per weather
     */
    public function __construct(
        public array $lapMs,
        public array $lapCashCents,
        public array $lapXp,
        public int $fuelPerLapMl,
        public WeatherSchedule $weather,
    ) {
        if ($lapMs === []) {
            throw new InvalidArgumentException('At least one weather is required.');
        }

        $weathers = array_keys($lapMs);
        sort($weathers);

        foreach ([$lapCashCents, $lapXp] as $table) {
            $keys = array_keys($table);
            sort($keys);

            if ($keys !== $weathers) {
                throw new InvalidArgumentException('Lap time, cash and XP must be defined for the same weathers.');
            }
        }

        foreach ($lapMs as $weather => $ms) {
            if ($ms <= 0) {
                throw new InvalidArgumentException("Lap time for {$weather} must be positive.");
            }
        }

        foreach ([...array_values($lapCashCents), ...array_values($lapXp)] as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Rewards cannot be negative.');
            }
        }

        if ($fuelPerLapMl <= 0) {
            throw new InvalidArgumentException('Fuel per lap must be positive.');
        }
    }

    /** A run with the same lap in every weather, as used by the first test vectors. */
    public static function constant(int $lapMs, int $lapCashCents, int $lapXp, int $fuelPerLapMl): self
    {
        return new self(['dry' => $lapMs], ['dry' => $lapCashCents], ['dry' => $lapXp], $fuelPerLapMl, new ConstantWeather('dry'));
    }

    public function lapMsFor(string $weather): int
    {
        return $this->lapMs[$weather] ?? throw new InvalidArgumentException("No lap time for weather {$weather}.");
    }

    public function lapCashFor(string $weather): int
    {
        return $this->lapCashCents[$weather] ?? throw new InvalidArgumentException("No reward for weather {$weather}.");
    }

    public function lapXpFor(string $weather): int
    {
        return $this->lapXp[$weather] ?? throw new InvalidArgumentException("No XP for weather {$weather}.");
    }
}
