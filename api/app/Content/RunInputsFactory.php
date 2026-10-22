<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Run\CarStats;
use App\Domain\Run\RunInputs;
use App\Domain\Run\TrackProfile;
use App\Domain\Run\WeatherEffect;
use InvalidArgumentException;

/**
 * Builds what a run is frozen from out of the content: a vehicle of the pack, a track and the rules.
 *
 * Until parts and tires can be chosen, every car drives on the stock tires of the rules and with the statistics
 * of its body: acceleration is power per tonne, top speed comes from the definition. This is the one place where
 * the floating point inputs of the freezer are made.
 */
final class RunInputsFactory
{
    /** Fuel use is 1 + 0.6 * power / reference power, relative to the car of the reference power (Systemy I). */
    private const FUEL_USE_BASE = 1.6;

    private const FUEL_USE_POWER_SHARE = 0.6;

    public function __construct(private readonly Catalog $catalog, private readonly Rules $rules)
    {
    }

    public function make(string $carSlug, string $trackSlug): RunInputs
    {
        $vehicle = $this->vehicle($carSlug);
        $track = $this->rules->track($trackSlug) ?? throw new InvalidArgumentException("Unknown track {$trackSlug}.");
        $region = $this->rules->region($track['region']) ?? throw new InvalidArgumentException("Unknown region {$track['region']}.");

        $car = $this->carStats($vehicle);

        $unsupported = array_diff(array_keys($track['demand']), array_keys($car->stats));

        if ($unsupported !== []) {
            throw new InvalidArgumentException("The track {$trackSlug} asks for statistics that cars do not have yet: ".implode(', ', $unsupported).'.');
        }

        $effects = [];

        foreach (array_keys($region['weather_ppm']) as $weather) {
            $effect = $this->rules->weather()[$weather];

            $effects[$weather] = new WeatherEffect(
                array_map(static fn (int $ppm): float => $ppm / 1_000_000, $effect['stat_ppm']),
                $effect['reward_ppm'],
                $effect['xp_ppm'],
            );
        }

        return new RunInputs(
            car: $car,
            track: new TrackProfile(
                weights: array_map(static fn (int $percent): float => $percent / 100, $track['demand']),
                references: array_map('floatval', $track['reference']),
                baseLapMs: $track['base_lap_ms'],
                fuelPerLapMl: $track['fuel_per_lap_ml'],
                rewardCentsPerLap: $track['reward_cents_per_lap'],
                xpPerLap: $track['xp_per_lap'],
            ),
            weatherChancesPpm: $region['weather_ppm'],
            weatherEffects: $effects,
            climateSeed: $region['climate_seed'],
            breakChancePerLapPpm: $this->rules->breakChancePerLapPpm(),
        );
    }

    /** What the tank of the car holds, in millilitres. */
    public function tankMl(string $carSlug): int
    {
        return (int) round($this->vehicle($carSlug)['tank_l'] * 1000);
    }

    /** @return array<string, mixed> */
    private function vehicle(string $slug): array
    {
        $vehicle = $this->catalog->vehicle($slug) ?? throw new InvalidArgumentException("Unknown car {$slug}.");

        if (($vehicle['powertrain'] ?? null) !== 'ice') {
            throw new InvalidArgumentException("The car {$slug} is not a combustion car, and only those can run for now.");
        }

        return $vehicle;
    }

    /** @param array<string, mixed> $vehicle */
    private function carStats(array $vehicle): CarStats
    {
        $tires = $this->catalog->part($this->rules->stockTires()) ?? throw new InvalidArgumentException("Unknown stock tires {$this->rules->stockTires()}.");
        $power = $vehicle['power_kw'];

        return new CarStats(
            stats: [
                'accel' => $power / ($vehicle['mass_kg'] / 1000),
                'top_speed' => (float) $vehicle['top_speed_kmh'],
                'grip' => (float) $tires['stats']['grip'],
                'handling' => (float) $tires['stats']['handling'],
                'braking' => (float) $tires['stats']['braking'],
            ],
            fuelUseMultiplier: (1.0 + self::FUEL_USE_POWER_SHARE * $power / $this->rules->fuelReferencePowerKw()) / self::FUEL_USE_BASE,
        );
    }
}
