<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Run\CarStats;
use App\Domain\Run\FieldProfile;
use App\Domain\Run\Rarity;
use App\Domain\Run\RunInputs;
use App\Domain\Run\Surface;
use App\Domain\Run\TrackProfile;
use App\Domain\Run\WearPlan;
use App\Domain\Run\WeatherEffect;
use App\Domain\Settlement\Ppm;
use App\Models\Car;
use App\Models\RaceRun;
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

    /** The statistics that depend on what the tires grip on: the matrix of the tires multiplies them. */
    private const TRACTION_STATS = ['grip', 'handling', 'braking'];

    /** @param  BotProfiles|null  $profiles  the references that the bots drive around: without them a car races alone */
    public function __construct(private readonly Catalog $catalog, private readonly Rules $rules, private readonly ?BotProfiles $profiles = null) {}

    /** The same factory for cars that race alone: what the place among the bots pays is left out, for a comparison of the cars themselves. */
    public function withoutBots(): self
    {
        return new self($this->catalog, $this->rules);
    }

    /**
     * @param  array<string, string|array{slug: string, rarity: int, upgrade?: int, wear?: int}>  $mounted  the parts on the car, by slot: the slug, or the slug with the rarity, the upgrade and the wear of the part
     * @param  int  $conditionPpm  the condition of the car (1 000 000 is perfect): a worn car breaks down more
     * @param  array{mileage_m: int, serviced_at_m: int}|null  $odometer  how far the car has driven and where it was serviced last; without it the car has no wear plan and no service
     */
    public function make(string $carSlug, string $trackSlug, array $mounted = [], int $conditionPpm = Ppm::ONE, ?array $odometer = null): RunInputs
    {
        $vehicle = $this->vehicle($carSlug);
        $track = $this->rules->track($trackSlug) ?? throw new InvalidArgumentException("Unknown track {$trackSlug}.");
        $region = $this->rules->region($track['region']) ?? throw new InvalidArgumentException("Unknown region {$track['region']}.");

        $plan = $odometer === null ? null : $this->wearPlan($carSlug, $trackSlug, $mounted, $odometer);
        $car = $this->stats($carSlug, $mounted, $plan?->servicePenaltyPpm() ?? 0);

        $unsupported = array_diff(array_keys($track['demand']), array_keys($car->stats));

        if ($unsupported !== []) {
            throw new InvalidArgumentException("The track {$trackSlug} asks for statistics that cars do not have yet: ".implode(', ', $unsupported).'.');
        }

        $tireKind = $this->tireKind($mounted);
        $effects = [];

        foreach (array_keys($region['weather_ppm']) as $weather) {
            $effect = $this->rules->weather()[$weather];
            $multipliersPpm = $effect['stat_ppm'];

            // The tires on this surface multiply what depends on the grip of the road, on top of what the weather does by itself.
            $tirePpm = $this->rules->tireGripPpm($tireKind, Surface::of($track['surface'], $effect['ground']));

            foreach (self::TRACTION_STATS as $stat) {
                $multipliersPpm[$stat] = Ppm::mul($tirePpm, $multipliersPpm[$stat] ?? Ppm::ONE);
            }

            $effects[$weather] = new WeatherEffect(
                array_map(static fn (int $ppm): float => $ppm / 1_000_000, $multipliersPpm),
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
                rewardCentsPerLap: Ppm::mul($track['reward_cents_per_lap'], $this->rules->carRewardPpm($vehicle['tier'])),
                xpPerLap: $track['xp_per_lap'],
            ),
            weatherChancesPpm: $region['weather_ppm'],
            weatherEffects: $effects,
            climateSeed: $region['climate_seed'],
            breakChancePerLapPpm: $this->rules->breakChancePerLapPpmFor($conditionPpm, $plan?->meanWearPpm() ?? 0, $plan?->overdueSharePpm() ?? 0, $plan?->unserviceableParts($this->rules->unserviceablePpm()) ?? 0),
            nightLapPpm: $track['lit'] ? Ppm::ONE : $this->rules->nightLapPpm(),
            field: $this->field($trackSlug, (int) $vehicle['tier']),
            wear: $plan,
        );
    }

    /**
     * What a run is frozen from when something about it changes: its car as it is now (the parts with their wear, the odometer, the service) on the
     * track it is on, or on another one. A run with no car of the driver (an old one) has the body of its car only.
     */
    public function forRun(RaceRun $run, ?string $trackSlug = null): RunInputs
    {
        return $run->car === null
            ? $this->make($run->car_slug, $trackSlug ?? $run->track_slug)
            : $this->makeForCar($run->car, $trackSlug ?? $run->track_slug);
    }

    /** The run of a car of the driver on a track: the parts it has with their wear, its condition, its odometer and its service. */
    public function makeForCar(Car $car, string $trackSlug): RunInputs
    {
        return $this->make($car->vehicle_slug, $trackSlug, $car->mountedParts(), $car->conditionPpm(), $car->odometer());
    }

    /**
     * What the wear of the parts on a car and its service come to on a track: how worn each part is, what a lap and a kilometre wear it by (on the ground of
     * the track, with the power of the car and the load of its engine), and the odometer. The floating point numbers are made here, once.
     *
     * @param  array<string, string|array{slug: string, rarity: int, upgrade?: int, wear?: int}>  $mounted  the parts on the car, by slot
     * @param  array{mileage_m: int, serviced_at_m: int}  $odometer
     */
    public function wearPlan(string $carSlug, string $trackSlug, array $mounted, array $odometer): WearPlan
    {
        $track = $this->rules->track($trackSlug) ?? throw new InvalidArgumentException("Unknown track {$trackSlug}.");
        $lengthM = $this->rules->trackLengthM($trackSlug);
        $power = $this->power($carSlug, $mounted);
        $load = $this->engineLoad($this->parts($mounted));
        $service = $this->rules->service();
        $wear = [];
        $perKm = [];
        $perLap = [];

        foreach ($mounted as $slot => $entry) {
            $wear[$slot] = is_array($entry) ? (int) ($entry['wear'] ?? 0) : 0;
            $perKm[$slot] = $this->rules->wearPerKmPpb($slot, $track['surface'], $power, $load);
            $perLap[$slot] = intdiv($perKm[$slot] * $lengthM + 500, 1000);
        }

        return new WearPlan(
            lengthM: $lengthM,
            mileageM: $odometer['mileage_m'],
            servicedAtM: $odometer['serviced_at_m'],
            wearPpb: $wear,
            perLapPpb: $perLap,
            perKmPpb: $perKm,
            stagePpb: $this->rules->wearStagePpb(),
            serviceEveryM: $service['every_km'] * 1000,
            serviceStepM: $service['step_km'] * 1000,
            serviceMaxSteps: $service['max_steps'],
            servicePenaltyPpmPerStep: $service['penalty_ppm_per_step'],
        );
    }

    /**
     * The power of a car with these parts on it, in kW: the power of the body times the `power_mult` of every part (as worn as it is), where the gain of a
     * booster is grown by the intercooler that works with it.
     *
     * @param  array<string, string|array{slug: string, rarity: int, upgrade?: int, wear?: int}>  $mounted
     */
    public function power(string $carSlug, array $mounted = []): float
    {
        return $this->vehicle($carSlug)['power_kw'] * $this->powerMultiplier($this->parts($mounted));
    }

    /**
     * What the parts multiply the power of the body by: the `power_mult` of each, and the gain of the booster (its `power_mult` over 1) grown by the
     * `boost_gain` of the intercooler (0.03 makes a gain of 25% one of 25.75%). An intercooler works with a booster only.
     *
     * @param  array<string, array<string, mixed>>  $parts  the definitions of the mounted parts, by slot
     * @param  bool  $withBooster  false leaves the gain of the booster out (it counts as 1), for what is paid for the engine and not for the boost
     */
    private function powerMultiplier(array $parts, bool $withBooster = true): float
    {
        $multiplier = 1.0;
        $intercooler = isset($parts['boost']) ? (float) ($parts['cooling']['stats']['boost_gain'] ?? 0.0) : 0.0;

        foreach ($parts as $slot => $part) {
            $value = (float) ($part['stats']['power_mult'] ?? 1.0);

            if ($slot === 'boost') {
                $multiplier *= $withBooster ? 1.0 + ($value - 1.0) * (1.0 + $intercooler) : 1.0;
            } else {
                $multiplier *= $value;
            }
        }

        return $multiplier;
    }

    /**
     * The load of the engine: what the booster asks of it (`engine_load` of the part, in points) less what the intercooler and the radiator take off, never
     * under nothing. It wears the parts of the engine faster (`Rules::wearPerKmPpb`). An intercooler works with a booster only, so without one it takes
     * nothing off.
     *
     * @param  array<string, array<string, mixed>>  $parts  the definitions of the mounted parts, by slot
     */
    private function engineLoad(array $parts): int
    {
        $load = 0.0;

        foreach ($parts as $part) {
            if (isset($part['stats']['boost_gain']) && ! isset($parts['boost'])) {
                continue;
            }

            $load += (float) ($part['stats']['engine_load'] ?? 0.0);
        }

        return max(0, (int) round($load));
    }

    /**
     * The bots that a car of this tier races against on this track: seven lap times around the reference of the tier (`bots.offsets_ppm` of the
     * rules), named after teams, and what each place pays. Null when the table of the profiles has no reference for the tier on the track.
     */
    private function field(string $trackSlug, int $tier): ?FieldProfile
    {
        $reference = $this->profiles?->referenceMs($trackSlug, $tier);

        if ($reference === null) {
            return null;
        }

        $offsets = $this->rules->botOffsetsPpm();
        $rivals = array_map(static fn (int $offset): int => intdiv($reference * (Ppm::ONE + $offset) + intdiv(Ppm::ONE, 2), Ppm::ONE), $offsets);

        return new FieldProfile($rivals, $this->botNames($trackSlug, count($offsets)), $this->rules->botRewardPpmByPosition());
    }

    /**
     * The names of the bots of a track: as many of the names of the rules as there are bots, in an order of their own for each track, so that every
     * driver sees the same rivals on the same track.
     *
     * @return list<string>
     */
    private function botNames(string $trackSlug, int $count): array
    {
        $names = $this->rules->botNames();

        usort($names, static fn (string $a, string $b): int => [crc32("{$trackSlug}|{$a}"), $a] <=> [crc32("{$trackSlug}|{$b}"), $b]);

        return array_slice($names, 0, $count);
    }

    /**
     * What the tank of the car holds, in millilitres: the tank mounted on it, or the one of the body.
     *
     * @param  array<string, string|array{slug: string, rarity: int, upgrade?: int, wear?: int}>  $mounted  the parts on the car, by slot
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
     * The statistics of a car with these parts on it: the pipeline of the design.
     *
     * - Power is the power of the body times the `power_mult` of every part (the gain of a booster grown by its intercooler), and acceleration is power per
     *   tonne, of the body and of the parts that weigh something (`mass_kg`), less the lag of the booster (1% a point) and with the launch of the gearbox.
     * - Top speed is the one of the body, grown with the cube root of the power gained, and never above the cap of the gearbox.
     * - Grip, handling and braking start from the tires, and the suspension and the brakes are averaged with the handling and
     *   the braking of the tires. A slot without a part adds nothing, so the stock car is the reference of the tracks.
     * - Handling and braking are then multiplied by the factor of the mass of the body (`Rules::massFactor`).
     * - Clearance (Off-road in the garage) starts from the body, and the suspension is averaged with it the same way:
     *   a lowered one costs a 4x4 what it gives a hatchback on asphalt. The tires have their say through the tire matrix.
     * - Fuel use grows with the power of the car without the gain of the booster, and the booster adds its own `fuel_mult` to that (so the gain is paid for
     *   once, not twice).
     *
     * Averaging is a first rule that the economy simulator will check; the numbers are in the definitions of the parts.
     *
     * The rarity of a part, its upgrade (+N) and its condition multiply what it adds (`Rules::scaledStats`), before any of this. A car that is overdue for
     * its service gives less of everything but its clearance: `$servicePenaltyPpm` is taken off the acceleration, the top speed, the grip, the handling and
     * the braking at the end.
     *
     * @param  array<string, string|array{slug: string, rarity: int, upgrade?: int, wear?: int}>  $mounted  the parts on the car, by slot
     * @param  int  $servicePenaltyPpm  what the overdue of the service takes off the performance, in ppm
     */
    public function stats(string $carSlug, array $mounted = [], int $servicePenaltyPpm = 0): CarStats
    {
        $vehicle = $this->vehicle($carSlug);
        $parts = $this->parts($mounted);
        $tires = $parts['tires'] ?? $this->stockTires();

        $powerMultiplier = $this->powerMultiplier($parts);
        $power = $vehicle['power_kw'] * $powerMultiplier;
        $topSpeed = $vehicle['top_speed_kmh'] * $powerMultiplier ** (1 / 3);

        if (isset($parts['gearbox']['stats']['top_speed_cap_kmh'])) {
            $topSpeed = min($topSpeed, (float) $parts['gearbox']['stats']['top_speed_cap_kmh']);
        }

        // The parts that weigh something (the intercoolers and the radiators, in kilograms, which may be less than nothing) are added to the body.
        $mass = $vehicle['mass_kg'] + array_sum(array_map(static fn (array $part): float => (float) ($part['stats']['mass_kg'] ?? 0.0), $parts));
        // A heavy car turns and stops worse, a light one better; the stock Rdzak has the mass the rules take as the reference.
        $massFactor = $this->rules->massFactor($mass);
        // What the booster takes off the acceleration for its lag, and the launch of the gearbox adds to it or takes off (in percent).
        $startFactor = $this->rules->lagFactor((float) ($parts['boost']['stats']['lag'] ?? 0.0)) * (1.0 + (float) ($parts['gearbox']['stats']['launch'] ?? 0.0) / 100.0);
        // The booster burns more through its own `fuel_mult`, so its gain is not counted again in the power that fuel use grows with.
        $fuelPower = $vehicle['power_kw'] * $this->powerMultiplier($parts, withBooster: false);
        $fuelMultiplier = array_product(array_map(static fn (array $part): float => (float) ($part['stats']['fuel_mult'] ?? 1.0), $parts));

        // A car that is overdue for its service performs worse: everything but the clearance, which is about the body, not the engine and the wheels.
        $kept = (Ppm::ONE - max(0, min(Ppm::ONE, $servicePenaltyPpm))) / Ppm::ONE;

        return new CarStats(
            stats: [
                'accel' => $power / ($mass / 1000) * $startFactor * $kept,
                'top_speed' => $topSpeed * $kept,
                'grip' => (float) $tires['stats']['grip'] * $kept,
                'handling' => $this->average($tires['stats']['handling'], $parts['suspension']['stats']['handling'] ?? null) * $massFactor * $kept,
                'braking' => $this->average($tires['stats']['braking'], $parts['brakes']['stats']['braking'] ?? null) * $massFactor * $kept,
                'clearance' => $this->average($vehicle['clearance'], $parts['suspension']['stats']['clearance'] ?? null),
            ],
            fuelUseMultiplier: (1.0 + self::FUEL_USE_POWER_SHARE * $fuelPower / $this->rules->fuelReferencePowerKw()) / self::FUEL_USE_BASE * $fuelMultiplier,
        );
    }

    /**
     * The definitions of the mounted parts, by slot. A slug that is not in the pack, or a part in a slot it does not
     * belong to, is a mistake of whoever saved it, not something to drive on.
     *
     * @param  array<string, string|array{slug: string, rarity: int, upgrade?: int, wear?: int}>  $mounted  the parts on the car, by slot
     * @return array<string, array<string, mixed>>
     */
    private function parts(array $mounted): array
    {
        $parts = [];

        foreach ($mounted as $slot => $entry) {
            $slug = is_array($entry) ? $entry['slug'] : $entry;
            $rarity = is_array($entry) ? Rarity::from($entry['rarity']) : Rarity::Common;
            $upgrade = is_array($entry) ? $entry['upgrade'] ?? 0 : 0;
            $part = $this->catalog->part($slug) ?? throw new InvalidArgumentException("Unknown part {$slug}.");

            if ($part['slot'] !== $slot) {
                throw new InvalidArgumentException("The part {$slug} belongs to the slot {$part['slot']}, not to {$slot}.");
            }

            $condition = $this->rules->stagedConditionPpm(is_array($entry) ? (int) ($entry['wear'] ?? 0) : 0);
            $part['stats'] = $this->rules->scaledStats($part['stats'] ?? [], $rarity, $upgrade, $condition);
            $parts[$slot] = $part;
        }

        return $parts;
    }

    /**
     * The kind of the tires on the car, which picks the column of the tire matrix: the stock tires when none are mounted.
     *
     * @param  array<string, string|array{slug: string, rarity: int, upgrade?: int, wear?: int}>  $mounted  the parts on the car, by slot
     */
    private function tireKind(array $mounted): string
    {
        $tires = $this->parts($mounted)['tires'] ?? $this->stockTires();

        return $tires['tire_kind'] ?? throw new InvalidArgumentException("The tires {$tires['slug']} have no kind, and the matrix cannot say how they grip.");
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
