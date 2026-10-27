<?php

declare(strict_types=1);

namespace App\Domain\Run;

use App\Domain\Settlement\Ppm;
use App\Domain\Settlement\RunConfig;
use InvalidArgumentException;

/**
 * The frozen configuration of a run: integers only, ready to be stored as JSON and read back.
 * Settlement never recomputes these numbers.
 */
final readonly class FrozenRun
{
    /**
     * @param  array{lap_ms: array<string, int>, lap_cash_cents: array<string, int>, lap_xp: array<string, int>, fuel_per_lap_ml: int, weather: array{seed: string, chances_ppm: array<string, int>}, night_lap_ppm?: int, wear?: array{length_m: int, ppb_per_lap: array<string, int>, ppb_per_km: array<string, int>}, field?: array{position: int, size: int, reward_ppm: int, rows: list<array{name: string|null, type: string, lap_ms: int}>}}  $config
     * @param  int|null  $breakAfterLaps  absolute lap number after which the car breaks down, null when no breakdown is planned
     * @param  int|null  $recomputeAfterLaps  absolute lap number after which the configuration has to be made again because a part wears to its next stage
     *                                        or the service moves on, null when nothing changes by itself
     */
    public function __construct(public array $config, public ?int $breakAfterLaps, public ?int $recomputeAfterLaps = null) {}

    /** The configuration that Settler needs, with the weather rebuilt from its seed. */
    public function runConfig(): RunConfig
    {
        return self::configFromArray($this->config);
    }

    /**
     * Rebuilds a RunConfig from the stored JSON array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function configFromArray(array $config): RunConfig
    {
        foreach (['lap_ms', 'lap_cash_cents', 'lap_xp', 'fuel_per_lap_ml', 'weather'] as $key) {
            if (! array_key_exists($key, $config)) {
                throw new InvalidArgumentException("The frozen configuration has no {$key}.");
            }
        }

        $weather = $config['weather'];

        return new RunConfig(
            $config['lap_ms'],
            $config['lap_cash_cents'],
            $config['lap_xp'],
            $config['fuel_per_lap_ml'],
            new SeededWeather((string) $weather['seed'], $weather['chances_ppm']),
            // A run frozen before the night had no such number: its laps are the same all day.
            (int) ($config['night_lap_ppm'] ?? Ppm::ONE),
        );
    }
}
