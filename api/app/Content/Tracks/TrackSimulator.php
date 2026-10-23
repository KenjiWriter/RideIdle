<?php

declare(strict_types=1);

namespace App\Content\Tracks;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\RunFreezer;
use App\Domain\Run\Surface;
use Generator;
use RuntimeException;

/**
 * The track simulator (Roadmapa: "symulator przed treścią", gate M3). It plays the real thing: every car of the pack with every
 * combination of the parts that change the lap, frozen by the same pipeline a run is frozen by, on every track of the rules.
 *
 * - A build is allowed on a track when it could have been made by a driver of the level of the track: the parts are no higher
 *   than the tier that a car of that level mounts (never lower than the level a new car starts with), and a car is there when it is
 *   a wreck to start with or its dealer level is not above the track.
 * - The best build on a track earns the most an hour in the climate of the region: every weather counts with its chance, its lap
 *   and the reward of its lap. The tires stay the same through the weather, which is the cost of not carrying a second set.
 *   Two builds that drive the same lap are told apart by what the parts cost, the cheaper winning.
 * - Apart from that, the tires are tried alone on every surface, with every tire of the pack, on one car and without the tier cap:
 *   this is the table of the design (Systemy II, "deszcz wymusza zmianę opon").
 */
final class TrackSimulator
{
    /** The slots whose parts change the lap of a car. The rest (cooling, tank) change what the car holds, not how fast it goes. */
    private const SLOTS = ['tires', 'suspension', 'brakes', 'exhaust', 'gearbox'];

    /** The weather in which the best build is also asked for, as the design's proof is dry against wet. */
    private const COMPARED_WEATHER = ['dry', 'rain'];

    public function __construct(
        private readonly Catalog $catalog,
        private readonly Rules $rules,
        private readonly RunInputsFactory $inputs,
    ) {}

    public function simulate(): TrackReport
    {
        $tracks = [];

        foreach ($this->rules->tracks() as $slug => $track) {
            $tracks[] = $this->winnerOn($slug, $track);
        }

        return new TrackReport($this->rules->version(), $this->surfaces(), $tracks);
    }

    /**
     * @param  array<string, mixed>  $track
     */
    private function winnerOn(string $slug, array $track): TrackOutcome
    {
        $region = $this->rules->region($track['region']);
        $tierCap = $this->rules->maxMountableTier(max($track['min_player_level'], $this->rules->carStartLevel()));

        $candidates = 0;
        $winner = null;
        $fastest = [];
        $byCar = [];

        foreach ($this->vehiclesFor($track['min_player_level']) as $vehicle) {
            foreach ($this->buildsOf($vehicle, $tierCap) as $build) {
                $candidates++;

                $frozen = $this->freeze($build, $slug);
                $income = 0.0;

                foreach ($region['weather_ppm'] as $weather => $chancePpm) {
                    $income += $chancePpm / 1_000_000 * $frozen['lap_cash_cents'][$weather] * 3_600_000 / $frozen['lap_ms'][$weather];
                }

                $byCar[$build->vehicle] = max($byCar[$build->vehicle] ?? 0.0, $income);

                if ($winner === null || $this->beats($income, $build, $winner['income'], $winner['build'])) {
                    $winner = ['income' => $income, 'build' => $build, 'lap_ms' => $frozen['lap_ms']];
                }

                foreach (self::COMPARED_WEATHER as $weather) {
                    $lap = $frozen['lap_ms'][$weather] ?? null;

                    if ($lap !== null && (! isset($fastest[$weather]) || $this->quicker($lap, $build, $fastest[$weather]))) {
                        $fastest[$weather] = ['build' => $build, 'lap_ms' => $lap];
                    }
                }
            }
        }

        if ($winner === null) {
            throw new RuntimeException("No car of the pack can drive the track {$slug}.");
        }

        return new TrackOutcome($slug, $track['surface'], $track['min_player_level'], $tierCap, $candidates, $winner['build'], $winner['income'], $winner['lap_ms'], $byCar, $fastest);
    }

    /** Whether a build earning `$income` is better than the one that is the best so far: more an hour, and for the same, cheaper. */
    private function beats(float $income, Build $build, float $bestIncome, Build $best): bool
    {
        if (abs($income - $bestIncome) > 1e-9) {
            return $income > $bestIncome;
        }

        return [$build->partsPriceCents, $build->label()] < [$best->partsPriceCents, $best->label()];
    }

    /**
     * @param  array{build: Build, lap_ms: int}  $best
     */
    private function quicker(int $lap, Build $build, array $best): bool
    {
        return $lap !== $best['lap_ms']
            ? $lap < $best['lap_ms']
            : [$build->partsPriceCents, $build->label()] < [$best['build']->partsPriceCents, $best['build']->label()];
    }

    /**
     * The tires of the pack, one after the other, on one car on every surface that the rules make.
     *
     * @return list<SurfaceOutcome>
     */
    private function surfaces(): array
    {
        $vehicle = $this->referenceVehicle();
        $tires = array_filter($this->catalog->parts(), static fn (array $part): bool => $part['slot'] === 'tires');
        ksort($tires);

        $outcomes = [];

        foreach (Surface::ALL as $surface) {
            $scenario = $this->scenarioFor($surface);

            if ($scenario === null || $vehicle === null || $tires === []) {
                continue;
            }

            [$track, $weather] = $scenario;
            $laps = [];
            $kinds = [];

            foreach ($tires as $slug => $part) {
                $laps[$slug] = $this->freeze(new Build($vehicle, ['tires' => $slug]), $track)['lap_ms'][$weather];
                $kinds[$slug] = $part['tire_kind'];
            }

            $outcomes[] = new SurfaceOutcome($surface, $track, $weather, $vehicle, $laps, $kinds);
        }

        return $outcomes;
    }

    /**
     * The first track of the rules, and a weather of its region, that make the surface.
     *
     * @return array{0: string, 1: string}|null
     */
    private function scenarioFor(string $surface): ?array
    {
        foreach ($this->rules->tracks() as $slug => $track) {
            foreach (array_keys($this->rules->region($track['region'])['weather_ppm']) as $weather) {
                if (Surface::of($track['surface'], $this->rules->weather()[$weather]['ground']) === $surface) {
                    return [$slug, $weather];
                }
            }
        }

        return null;
    }

    /** The cheapest of the wrecks a driver starts with: the car the tires are tried on. */
    private function referenceVehicle(): ?string
    {
        $starting = $this->catalog->startingVehicles();
        uasort($starting, static fn (array $a, array $b): int => [$a['price_cents'], $a['slug']] <=> [$b['price_cents'], $b['slug']]);

        return array_key_first($starting);
    }

    /**
     * The cars a driver of the level can drive: the wrecks of the start, and what the dealer sells from that level. Only the
     * ones that burn fuel, because those are the ones that can run for now.
     *
     * @return list<array<string, mixed>>
     */
    private function vehiclesFor(int $level): array
    {
        $vehicles = array_filter(
            $this->catalog->vehicles(),
            static fn (array $vehicle): bool => $vehicle['powertrain'] === 'ice' && (in_array('start', $vehicle['sources'], true) || $vehicle['min_player_level'] <= $level),
        );
        ksort($vehicles);

        return array_values($vehicles);
    }

    /**
     * Every combination of the parts that change the lap, up to a tier, and the car without a part in a slot among them.
     *
     * @param  array<string, mixed>  $vehicle
     * @return Generator<int, Build>
     */
    private function buildsOf(array $vehicle, int $tierCap): Generator
    {
        $options = [];

        foreach (self::SLOTS as $slot) {
            $parts = array_filter(
                $this->catalog->parts(),
                fn (array $part): bool => $part['slot'] === $slot && $part['tier'] <= $tierCap && $this->fits($part, $vehicle),
            );
            ksort($parts);

            $options[$slot] = [null, ...array_values($parts)];
        }

        yield from $this->combinations($vehicle['slug'], array_keys($options), $options, [], 0);
    }

    /**
     * @param  list<string>  $slots
     * @param  array<string, list<array<string, mixed>|null>>  $options
     * @param  array<string, string>  $mounted
     * @return Generator<int, Build>
     */
    private function combinations(string $vehicle, array $slots, array $options, array $mounted, int $price): Generator
    {
        if ($slots === []) {
            yield new Build($vehicle, $mounted, $price);

            return;
        }

        $slot = array_shift($slots);

        foreach ($options[$slot] as $part) {
            yield from $part === null
                ? $this->combinations($vehicle, $slots, $options, $mounted, $price)
                : $this->combinations($vehicle, $slots, $options, [...$mounted, $slot => $part['slug']], $price + $part['price_cents']);
        }
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  array<string, mixed>  $vehicle
     */
    private function fits(array $part, array $vehicle): bool
    {
        $fits = $part['fits'] ?? [];

        return (! isset($fits['powertrain']) || $fits['powertrain'] === $vehicle['powertrain'])
            && (! isset($fits['classes']) || in_array($vehicle['class'], $fits['classes'], true));
    }

    /** @return array{lap_ms: array<string, int>, lap_cash_cents: array<string, int>} */
    private function freeze(Build $build, string $track): array
    {
        $config = (new RunFreezer)->freeze($this->inputs->make($build->vehicle, $track, $build->mounted), 'tracks', 0)->config;

        return ['lap_ms' => $config['lap_ms'], 'lap_cash_cents' => $config['lap_cash_cents']];
    }
}
