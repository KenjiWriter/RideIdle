<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\CarStats;
use App\Domain\Run\RoadMap;
use App\Domain\Run\RunFreezer;
use App\Exceptions\RuleRefusedException;
use App\Models\Car;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Travel as Journey;
use App\Support\I18nText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Travel between the cities of the map: a car leaves for another city, stands on the road for as long as the journey takes, and arrives with
 * the track of that city frozen into its run. Like the jobs of the workshop, nothing runs in the background: an arrival is carried out the
 * next time the driver, or anything that settles the run, asks (`advance`). Every car of the driver can be on the road at once.
 *
 * - A journey takes the shortest way over the roads, at a share of the top speed of the car, and burns fuel by the kilometre, so a car with
 *   more power gets there sooner and a thirsty one pays more. It earns nothing on the road.
 * - A city is open from the level of the driver that its tracks ask for, and the driver needs a garage there with a place for the car.
 * - Only a car that is driving can leave: one that stands (a breakdown, an empty tank, the workshop) has to be got going first, and one that
 *   still has a job waiting in the workshop of its city stays for it.
 *
 * Everything runs under the lock of the wallet and then the runs, like the settlement and the workshop.
 */
final class Travel
{
    public function __construct(
        private readonly RunService $runs,
        private readonly Ledger $ledger,
        private readonly Rules $rules,
        private readonly RunInputsFactory $inputs,
        private readonly Garage $garage,
        private readonly CityGarages $garages,
        private readonly TrackChoices $choices,
        private readonly Premium $premium,
    ) {}

    /** Carries out the arrivals that are due, if there are any. Safe to call at any time; a cheap read when nobody is on the road. */
    public function advance(int $userId, ?int $nowMs = null): void
    {
        if (! Journey::query()->where('user_id', $userId)->where('status', Journey::STATUS_TRAVELLING)->exists()) {
            return;
        }

        $this->locked($userId, $nowMs, function (Collection $runs, int $now) use ($userId): void {
            $this->arriveLocked($userId, $runs, $now);
        });
    }

    /**
     * Brings the car, now, to the city it is going to, for premium coins: one for every started block of minutes that was left (`Rules::speedupCoins`). The car
     * is there from this moment and drives from it, as if the time had passed.
     *
     * `$requestId` is one intention: sent again it charges nothing and moves nothing (null).
     *
     * @param  int|null  $carId  the car, or the one the driver plays when left out
     * @return array{journey: Journey, coins: int}|null what was finished and what it cost, or null when this request was served before
     *
     * @throws RuleRefusedException `speedup_unavailable`, `no_run` or `car_not_found`, `not_travelling` or `insufficient_premium` (nothing is changed)
     */
    public function finish(int $userId, ?int $carId, string $requestId, ?int $nowMs = null): ?array
    {
        return $this->locked($userId, $nowMs, function (Collection $runs, int $now) use ($userId, $carId, $requestId): ?array {
            $car = $carId === null
                ? $this->garage->carOf($userId)
                : Car::query()->where('user_id', $userId)->find($carId);

            $run = $car === null ? null : $runs->get($car->id);

            if ($car === null || $run === null) {
                throw new RuleRefusedException($carId === null ? 'no_run' : 'car_not_found');
            }

            $key = "speedup:travel:{$run->id}:{$requestId}";

            if (LedgerEntry::query()->where('idem_key', $key)->exists()) {
                return null;
            }

            // What is due comes first: a car that has just arrived is there, and is not paid for.
            $this->arriveLocked($userId, $runs, $now);

            $journey = $this->activeOf($run->id) ?? throw new RuleRefusedException('not_travelling');
            $coins = $this->rules->speedupCoins($journey->arrive_ms - $now) ?? throw new RuleRefusedException('speedup_unavailable');

            $this->premium->spend($userId, $coins, 'speedup', $key);

            $journey->forceFill(['arrive_ms' => $now])->save();
            $this->arriveLocked($userId, $runs, $now);

            return ['journey' => $journey->refresh(), 'coins' => $coins];
        });
    }

    /** @return array{coins: int, remaining_ms: int}|null */
    private function speedupOf(int $remainingMs): ?array
    {
        $coins = $this->rules->speedupCoins($remainingMs);

        return $coins === null ? null : ['coins' => $coins, 'remaining_ms' => max(0, $remainingMs)];
    }

    /** The journey the car of a run is on, or null. */
    public function activeOf(int $runId): ?Journey
    {
        return Journey::query()->where('race_run_id', $runId)->where('status', Journey::STATUS_TRAVELLING)->first();
    }

    /**
     * Sends a car of the driver to another city: the car they play, or the one asked for.
     *
     * @throws RuleRefusedException the car is not the driver's or has no run, cannot leave, is there already or on the road, the city does not
     *                              exist or has no road to it, the level of the driver does not open it, there is no place for the car
     *                              there, or the fuel does not reach
     */
    public function start(int $userId, string $to, ?int $carId = null, ?int $nowMs = null): Journey
    {
        return $this->locked($userId, $nowMs, function (Collection $runs, int $now) use ($userId, $to, $carId): Journey {
            // What is due comes first: a car that has just arrived somewhere is there, not on the road.
            $this->arriveLocked($userId, $runs, $now);

            $car = $carId === null
                ? $this->garage->carOf($userId)
                : Car::query()->where('user_id', $userId)->find($carId);

            $run = $car === null ? null : $runs->get($car->id);

            if ($car === null || $run === null) {
                throw new RuleRefusedException($carId === null ? 'no_run' : 'car_not_found');
            }

            if (! isset($this->rules->regions()[$to])) {
                throw new RuleRefusedException('city_not_found');
            }

            if ($this->activeOf($run->id) !== null) {
                throw new RuleRefusedException('already_travelling');
            }

            $from = $this->garage->cityOf($run);

            if ($from === $to) {
                throw new RuleRefusedException('already_there');
            }

            $km = (new RoadMap($this->rules->roads()))->distanceKm($from, $to) ?? throw new RuleRefusedException('no_road');

            $required = $this->rules->cityMinPlayerLevel($to);
            $current = $this->rules->playerLevelAt($this->ledger->xp($userId));

            if ($current < $required) {
                throw new RuleRefusedException('city_locked', ['required' => $required, 'current' => $current]);
            }

            // The car needs a place to stand where it goes, and the driver has to have bought the garage there.
            if (! $this->garages->of($userId)->contains('region', $to)) {
                throw new RuleRefusedException('no_garage', ['city' => $this->rules->cityName($to)]);
            }

            if (! $this->garages->hasRoomIn($userId, $to)) {
                throw new RuleRefusedException('garage_full', ['city' => $this->rules->cityName($to)]);
            }

            // The car earns until it leaves, and a car that cannot go on (the tank ran dry, it broke down) stays where it is.
            $this->runs->settleLocked($run, $now);

            if ($run->status !== RaceRun::STATUS_RUNNING || $this->garages->hasWork($car)) {
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
     * The map as a driver sees it, for the car they play: the cities and roads, where that car is, what each city asks and costs to get to
     * from there, how well the car suits its track, the garage the driver has there, and the journey the car is on, if any.
     *
     * @return array<string, mixed>
     */
    public function map(int $userId, int $nowMs): array
    {
        $car = $this->garage->carOf($userId);
        $run = $car === null ? null : $this->garage->runOf($car);
        $journey = $run === null ? null : $this->activeOf($run->id);
        $here = $run === null ? $this->rules->homeRegion() : $this->garage->cityOf($run);
        $level = $this->rules->playerLevelAt($this->ledger->xp($userId));
        $stats = $run === null ? null : $this->statsOf($run);
        $roads = new RoadMap($this->rules->roads());
        $garages = $this->garages->summary($userId);
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
                'garage' => $garages[$slug],
                'track' => [
                    'slug' => $trackSlug,
                    'name' => I18nText::from($track['name'])->for(),
                    'kind' => $this->rules->trackKind($trackSlug),
                    'surface' => $track['surface'],
                    'lit' => $track['lit'],
                    'base_lap_ms' => $track['base_lap_ms'],
                    'demand' => $track['demand'],
                ],
                // Every track of the city, with what each is for the car that is played: the driver chooses among them where the car stands.
                'tracks' => $this->choices->forCity($slug, $run, $level),
                // How the car, with the parts it has, runs this track in the dry against the lap of a car that matches its reference.
                'fit_percent' => $run === null ? null : (int) round(100 * $track['base_lap_ms'] / $freezer->freeze($this->inputs->forRun($run, $trackSlug), 'map', 0)->config['lap_ms']['dry']),
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
                // What finishing the journey at once costs in premium coins, or null when the rules sell nothing.
                'speedup' => $this->speedupOf($journey->arrive_ms - $nowMs),
            ],
            'now_ms' => $nowMs,
        ];
    }

    private function statsOf(RaceRun $run): CarStats
    {
        return $this->inputs->stats($run->car_slug, $run->car?->mountedParts() ?? []);
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

    /**
     * The arrivals that are due: each run takes the track of the city it came to, and drives from the moment it arrived.
     *
     * @param  Collection<int, RaceRun>  $runs  the runs of the driver, by car
     */
    private function arriveLocked(int $userId, Collection $runs, int $now): void
    {
        $due = Journey::query()->where('user_id', $userId)->where('status', Journey::STATUS_TRAVELLING)->where('arrive_ms', '<=', $now)->orderBy('arrive_ms')->lockForUpdate()->get();

        foreach ($due as $journey) {
            $run = $runs->firstWhere('id', $journey->race_run_id);

            if ($run !== null) {
                $track = $this->rules->tracksOf($journey->to_region)[0];

                // The kilometres of the road are on its odometer and on its parts, whatever it was frozen with when it left.
                if ($run->car_id !== null) {
                    (new CarWear)->applyDistance($run->car_id, $run->config, (int) $journey->distance_km);
                    $run->unsetRelation('car');
                }

                $this->runs->arrive($run, $this->inputs->forRun($run, $track), $track, $journey->arrive_ms);
            }

            $journey->forceFill(['status' => Journey::STATUS_ARRIVED])->save();
        }
    }

    /**
     * Runs `$work` with the wallet of the driver and then all their runs locked, always in this order (the runs by id), like the settlement of a
     * run, so two requests cannot wait for each other. The clock is read after the locks.
     *
     * @template T
     *
     * @param  callable(Collection<int, RaceRun>, int): T  $work  gets the runs of the driver by car
     * @return T
     */
    private function locked(int $userId, ?int $nowMs, callable $work): mixed
    {
        // Before the transaction: creating the wallet under a lock deadlocks (see Ledger::ensureWallet).
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $nowMs, $work): mixed {
            $this->ledger->lockWallet($userId);
            $runs = RaceRun::query()->where('user_id', $userId)->orderBy('id')->lockForUpdate()->get()->keyBy('car_id');

            return $work($runs, $nowMs ?? RunService::nowMs());
        }, Ledger::DEADLOCK_ATTEMPTS);
    }
}
