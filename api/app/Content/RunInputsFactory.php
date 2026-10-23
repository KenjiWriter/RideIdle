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
 * A car is its body plus the parts mounted on it (see `stats`), and a car without a part in a slot has the factory one
 * of that slot: the stock tires of the rules, and nothing that changes the statistics for the others. This is the one place
 * where the floating point inputs of the freezer are made.
 */
final class RunInputsFactory
{
    /** Fuel use is 1 + 0.6 * power / reference power, relative to the car of the reference power (Systemy I). */
    private const FUEL_USE_BASE = 1.6;

    private const FUEL_USE_POWER_SHARE = 0.6;

    public function __construct(private readonly Catalog $catalog, private readonly Rules $rules) {}

    /** @param  array<string, string>  $mounted  slugs of the parts on the car, by slot */
    public function make(string $carSlug, string $trackSlug, array $mounted = []): RunInputs
    {
        $this->vehicle($carSlug);
        $track = $this->rules->track($trackSlug) ?? throw new InvalidArgumentException("Unknown track {$trackSlug}.");
        $region = $this->rules->region($track['region']) ?? throw new InvalidArgumentException("Unknown region {$track['region']}.");

        $car = $this->stats($carSlug, $mounted);

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

    /**
     * What the tank of the car holds, in millilitres: the tank mounted on it, or the one of the body.
     *
     * @param  array<string, string>  $mounted  slugs of the parts on the car, by slot
     */
    public function tankMl(string $carSlug, array $mounted = []): int
    {
        $tank = $this->parts($mounted)['tank']['stats']['capacity_l'] ?? $this->vehicle($carSlug)['tank_l'];

        return (int) round($tank * 1000);
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

    /**
     * The statistics of a car with these parts on it: the pipeline of the design without rarity and upgrades.
     *
     * - Power is the power of the body times the `power_mult` of every part, and acceleration is power per tonne.
     * - Top speed is the one of the body, grown with the cube root of the power gained, and never above the cap of the gearbox.
     * - Grip, handling and braking start from the tires, and the suspension and the brakes are averaged with the handling and
     *   the braking of the tires. A slot without a part adds nothing, so the stock car is the reference of the tracks.
     * - Fuel use grows with power.
     *
     * Averaging is a first rule that the economy simulator will check; the numbers are in the definitions of the parts.
     *
     * @param  array<string, string>  $mounted  slugs of the parts on the car, by slot
     */
    public function stats(string $carSlug, array $mounted = []): CarStats
    {
        $vehicle = $this->vehicle($carSlug);
        $parts = $this->parts($mounted);
        $tires = $parts['tires'] ?? $this->stockTires();

        $powerMultiplier = 1.0;

        foreach ($parts as $part) {
            $powerMultiplier *= (float) ($part['stats']['power_mult'] ?? 1.0);
        }

        $power = $vehicle['power_kw'] * $powerMultiplier;
        $topSpeed = $vehicle['top_speed_kmh'] * $powerMultiplier ** (1 / 3);

        if (isset($parts['gearbox']['stats']['top_speed_cap_kmh'])) {
            $topSpeed = min($topSpeed, (float) $parts['gearbox']['stats']['top_speed_cap_kmh']);
        }

        return new CarStats(
            stats: [
                'accel' => $power / ($vehicle['mass_kg'] / 1000),
                'top_speed' => $topSpeed,
                'grip' => (float) $tires['stats']['grip'],
                'handling' => $this->average($tires['stats']['handling'], $parts['suspension']['stats']['handling'] ?? null),
                'braking' => $this->average($tires['stats']['braking'], $parts['brakes']['stats']['braking'] ?? null),
            ],
            fuelUseMultiplier: (1.0 + self::FUEL_USE_POWER_SHARE * $power / $this->rules->fuelReferencePowerKw()) / self::FUEL_USE_BASE,
        );
    }

    /**
     * The definitions of the mounted parts, by slot. A slug that is not in the pack, or a part in a slot it does not
     * belong to, is a mistake of whoever saved it, not something to drive on.
     *
     * @param  array<string, string>  $mounted  slugs of the parts on the car, by slot
     * @return array<string, array<string, mixed>>
     */
    private function parts(array $mounted): array
    {
        $parts = [];

        foreach ($mounted as $slot => $slug) {
            $part = $this->catalog->part($slug) ?? throw new InvalidArgumentException("Unknown part {$slug}.");

            if ($part['slot'] !== $slot) {
                throw new InvalidArgumentException("The part {$slug} belongs to the slot {$part['slot']}, not to {$slot}.");
            }

            $parts[$slot] = $part;
        }

        return $parts;
    }

    /** @return array<string, mixed> */
    private function stockTires(): array
    {
        return $this->catalog->part($this->rules->stockTires()) ?? throw new InvalidArgumentException("Unknown stock tires {$this->rules->stockTires()}.");
    }

    /** The mean of two values, or the first one when there is no second. */
    private function average(int|float $base, int|float|null $other): float
    {
        return $other === null ? (float) $base : ($base + $other) / 2;
    }
}
