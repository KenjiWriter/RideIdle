<?php

declare(strict_types=1);

namespace App\Domain\Run;

use App\Domain\Settlement\RunConfig;
use InvalidArgumentException;

/**
 * The frozen configuration of a run: integers only, ready to be stored as JSON and read back.
 * Settlement never recomputes these numbers.
 */
final readonly class FrozenRun
{
    /**
     * @param  array{lap_ms: array<string, int>, lap_cash_cents: array<string, int>, lap_xp: array<string, int>, fuel_per_lap_ml: int, weather: array{seed: string, chances_ppm: array<string, int>}}  $config
     * @param  int|null  $breakAfterLaps  absolute lap number after which the car breaks down, null when no breakdown is planned
     */
    public function __construct(public array $config, public ?int $breakAfterLaps)
    {
    }

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
        );
    }
}
