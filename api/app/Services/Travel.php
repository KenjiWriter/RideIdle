<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\CarStats;
use App\Domain\Run\RoadMap;
use App\Domain\Run\RunFreezer;
use App\Exceptions\RuleRefusedException;
use App\Models\RaceRun;
use App\Models\Travel as Journey;
use App\Support\I18nText;
use Illuminate\Support\Facades\DB;

/**
 * Travel between the cities of the map (a prototype of the map of the design, Mapa i podróż): the car leaves for another city, stands on the
 * road for as long as the journey takes, and arrives with the track of that city frozen into its run. Like the jobs of the workshop, nothing
 * runs in the background: an arrival is carried out the next time the driver, or anything that settles the run, asks (`advance`).
 *
 * - A journey takes the shortest way over the roads, at a share of the top speed of the car, and burns fuel by the kilometre, so a car with
 *   more power gets there sooner and a thirsty one pays more. It earns nothing on the road.
 * - A city is open from the level of the driver that its tracks ask for. A closed one cannot be travelled to, and says what it takes.
 * - Only a car that is driving can leave: one that stands (a breakdown, an empty tank, the workshop) has to be got going first.
 *
 * Everything runs under the lock of the wallet and then the run, like the settlement and the workshop.
 */
final class Travel
{
    public function __construct(
        private readonly RunService $runs,
        private readonly Ledger $ledger,
        private readonly Rules $rules,
        private readonly RunInputsFactory $inputs,
    ) {}

    /** Carries out the arrival that is due, if there is one. Safe to call at any time; a cheap read when nobody is on the road. */
    public function advance(int $userId, ?int $nowMs = null): void
    {
        if ($this->active($userId) === null) {
            return;
        }

        $this->locked($userId, $nowMs, function (?RaceRun $run, int $now) use ($userId): void {
            $this->arriveLocked($userId, $run, $now);
        });
    }

    /** The journey the driver is on, or null. */
    public function active(int $userId): ?Journey
    {
        return Journey::query()->where('user_id', $userId)->where('status', Journey::STATUS_TRAVELLING)->first();
    }

    /**
     * Sends the car of the driver to another city.
     *
     * @throws RuleRefusedException the car has no run, cannot leave, is there already or on the road, the city does not exist or has no road
     *                              to it, the level of the driver does not open it, or the fuel does not reach
     */
    public function start(int $userId, string $to, ?int $nowMs = null): Journey
    {
        return $this->locked($userId, $nowMs, function (?RaceRun $run, int $now) use ($userId, $to): Journey {
            // What is due comes first: a car that has just arrived somewhere is there, not on the road.
            $this->arriveLocked($userId, $run, $now);

            $run ?? throw new RuleRefusedException('no_run');

            if (! isset($this->rules->regions()[$to])) {
                throw new RuleRefusedException('city_not_found');
            }

            if ($this->active($userId) !== null) {
                throw new RuleRefusedException('already_travelling');
            }

            $from = $this->cityOf($run);

            if ($from === $to) {
                throw new RuleRefusedException('already_there');
            }

            $km = (new RoadMap($this->rules->roads()))->distanceKm($from, $to) ?? throw new RuleRefusedException('no_road');

            $required = $this->rules->cityMinPlayerLevel($to);
            $current = $this->rules->playerLevelAt($this->ledger->xp($userId));

            if ($current < $required) {
                throw new RuleRefusedException('city_locked', ['required' => $required, 'current' => $current]);
            }

            // The car earns until it leaves, and a car that cannot go on (the tank ran dry, it broke down) stays where it is.
            $this->runs->settleLocked($run, $now);

            if ($run->status !== RaceRun::STATUS_RUNNING) {
                throw new RuleRefusedException('car_cannot_travel');
            }

            ['ms' => $ms, 'fuel_ml' => $fuelMl] = $this->journeyOf($km, $this->statsOf($run));

            if ($run->fuel_ml < $fuelMl) {
                throw new RuleRefusedException('travel_no_fuel', [
                    'required' => number_format($fuelMl / 1000, 1, '.', ''),
                    'available' => number_format($run->fuel_ml / 1000, 1, '.', ''),
                ]);
            }

            $this->runs->depart($run, $fuelMl);

            return Journey::query()->create([
                'user_id' => $userId,
                'race_run_id' => $run->id,
                'from_region' => $from,
                'to_region' => $to,
                'distance_km' => $km,
                'fuel_ml' => $fuelMl,
                'start_ms' => $now,
                'arrive_ms' => $now + $ms,
                'status' => Journey::STATUS_TRAVELLING,
            ]);
        });
    }

    /**
     * The map as a driver sees it: the cities and roads, where the car is, what each city asks and costs to get to from here, how well
     * the car suits its track, and the journey on the way, if any.
     *
     * @return array<string, mixed>
     */
    public function map(int $userId, int $nowMs): array
    {
        $run = RaceRun::query()->where('user_id', $userId)->orderByDesc('id')->first();
        $journey = $this->active($userId);
        $here = $run === null ? $this->rules->homeRegion() : $this->cityOf($run);
        $level = $this->rules->playerLevelAt($this->ledger->xp($userId));
        $stats = $run === null ? null : $this->statsOf($run);
        $roads = new RoadMap($this->rules->roads());
        $freezer = new RunFreezer;
        $cities = [];

        foreach ($this->rules->regions() as $slug => $region) {
            $trackSlug = $this->rules->tracksOf($slug)[0];
            $track = $this->rules->track($trackSlug);
            $required = $this->rules->cityMinPlayerLevel($slug);
            $km = $run === null || $journey !== null || $slug === $here ? null : $roads->distanceKm($here, $slug);

            $cities[] = [
                'slug' => $slug,
                'name' => I18nText::from($region['name'])->for(),
                'x' => $region['map']['x'],
                'y' => $region['map']['y'],
                'home' => $slug === $this->rules->homeRegion(),
                'here' => $slug === $here,
                'level_required' => $required,
                'open' => $level >= $required,
                'track' => [
                    'slug' => $trackSlug,
                    'name' => I18nText::from($track['name'])->for(),
                    'surface' => $track['surface'],
                    'lit' => $track['lit'],
                    'base_lap_ms' => $track['base_lap_ms'],
                    'demand' => $track['demand'],
                ],
                // How the car, with the parts it has, runs this track in the dry against the lap of a car that matches its reference.
                'fit_percent' => $run === null ? null : (int) round(100 * $track['base_lap_ms'] / $freezer->freeze($this->inputs->make($run->car_slug, $trackSlug, $run->car?->mountedSlugs() ?? []), 'map', 0)->config['lap_ms']['dry']),
                'route' => $km === null || $stats === null ? null : ['km' => $km, ...$this->journeyOf($km, $stats)],
            ];
        }

        return [
            'here' => $here,
            'level' => $level,
            'cities' => $cities,
            'roads' => $this->rules->roads(),
            'travel' => $journey === null ? null : [
                'from' => $journey->from_region,
                'to' => $journey->to_region,
                'distance_km' => $journey->distance_km,
                'start_ms' => $journey->start_ms,
                'arrive_ms' => $journey->arrive_ms,
            ],
            'now_ms' => $nowMs,
        ];
    }

    /** The city a car is in: the region of the track it drives on. */
    private function cityOf(RaceRun $run): string
    {
        return (string) ($this->rules->track($run->track_slug)['region'] ?? $this->rules->homeRegion());
    }

    private function statsOf(RaceRun $run): CarStats
    {
        return $this->inputs->stats($run->car_slug, $run->car?->mountedSlugs() ?? []);
    }

    /**
     * What a journey of so many km takes a car: the time, at a share of its top speed, and the fuel, by the kilometre and the thirst of the car.
     *
     * @return array{ms: int, fuel_ml: int}
     */
    private function journeyOf(int $km, CarStats $stats): array
    {
        $speedKmh = $stats->get('top_speed') * $this->rules->travelSpeedSharePpm() / 1_000_000;

        return [
            'ms' => max(1, (int) round($km / $speedKmh * 3_600_000)),
            'fuel_ml' => max(1, (int) ceil($km * $this->rules->travelFuelMlPerKm() * $stats->fuelUseMultiplier)),
        ];
    }

    /** The arrival that is due: the run takes the track of the city it came to, and drives from the moment it arrived. */
    private function arriveLocked(int $userId, ?RaceRun $run, int $now): void
    {
        $journey = Journey::query()->where('user_id', $userId)->where('status', Journey::STATUS_TRAVELLING)->lockForUpdate()->first();

        if ($journey === null || $journey->arrive_ms > $now) {
            return;
        }

        if ($run !== null && $run->id === $journey->race_run_id) {
            $track = $this->rules->tracksOf($journey->to_region)[0];

            $this->runs->arrive($run, $this->inputs->make($run->car_slug, $track, $run->car?->mountedSlugs() ?? []), $track, $journey->arrive_ms);
        }

        $journey->forceFill(['status' => Journey::STATUS_ARRIVED])->save();
    }

    /**
     * Runs `$work` with the wallet of the driver and then their run locked, always in this order, like the settlement of a run, so two
     * requests cannot wait for each other. The clock is read after the locks.
     *
     * @template T
     *
     * @param  callable(?RaceRun, int): T  $work
     * @return T
     */
    private function locked(int $userId, ?int $nowMs, callable $work): mixed
    {
        // Before the transaction: creating the wallet under a lock deadlocks (see Ledger::ensureWallet).
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $nowMs, $work): mixed {
            $this->ledger->lockWallet($userId);
            $run = RaceRun::query()->where('user_id', $userId)->orderByDesc('id')->lockForUpdate()->first();

            return $work($run, $nowMs ?? RunService::nowMs());
        }, Ledger::DEADLOCK_ATTEMPTS);
    }
}
