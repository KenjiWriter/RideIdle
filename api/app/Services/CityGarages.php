<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Exceptions\RuleRefusedException;
use App\Models\Car;
use App\Models\CityGarage;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Travel as Journey;
use App\Models\WorkshopJob;
use App\Support\I18nText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The garages of the cities (Roadmapa F2, "garaże w miastach, zaparkowane auta jeżdżą same"): a garage holds a few cars, which drive on the
 * track of its city by themselves. The garage of the home city is there from the start, and the others are bought, in a city the level of
 * the driver opens. A car has to have a place to stand where it goes, and a wreck from the yard of a city is bought into a garage there.
 *
 * Everything that changes money or cars runs under the lock of the wallet, like the settlement.
 */
final class CityGarages
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Rules $rules,
        private readonly Catalog $catalog,
        private readonly Garage $garage,
        private readonly RunService $runs,
        private readonly RunInputsFactory $inputs,
    ) {}

    /**
     * The garages of the driver, the oldest first. The one of the home city is made when it is first asked for.
     *
     * @return Collection<int, CityGarage>
     */
    public function of(int $userId): Collection
    {
        CityGarage::query()->firstOrCreate(
            ['user_id' => $userId, 'region' => $this->rules->homeRegion()],
            ['capacity' => $this->rules->garageCapacity()],
        );

        return CityGarage::query()->where('user_id', $userId)->orderBy('id')->get();
    }

    /** How many cars of the driver stand in a city: a car on the road stands in the city it is going to, because the place is kept for it. */
    public function usedIn(int $userId, string $region): int
    {
        $arriving = Journey::query()->where('user_id', $userId)->where('status', Journey::STATUS_TRAVELLING)->pluck('to_region', 'race_run_id');

        return RaceRun::query()->where('user_id', $userId)->get()
            ->filter(fn (RaceRun $run): bool => ($arriving[$run->id] ?? $this->garage->cityOf($run)) === $region)
            ->count();
    }

    /** Whether the driver has a place for one more car in a city. */
    public function hasRoomIn(int $userId, string $region): bool
    {
        $garage = $this->of($userId)->firstWhere('region', $region);

        return $garage !== null && $this->usedIn($userId, $region) < $garage->capacity;
    }

    /**
     * What every city offers the driver: whether they have a garage there, how full it is, and what one costs.
     *
     * @return array<string, array{owned: bool, capacity: int, used: int, free: int, price_cents: int|null}>
     */
    public function summary(int $userId): array
    {
        $owned = $this->of($userId)->keyBy('region');
        $summary = [];

        foreach (array_keys($this->rules->regions()) as $region) {
            $garage = $owned->get($region);
            $used = $this->usedIn($userId, $region);

            $summary[$region] = [
                'owned' => $garage !== null,
                'capacity' => $garage?->capacity ?? $this->rules->garageCapacity(),
                'used' => $used,
                'free' => $garage === null ? 0 : max(0, $garage->capacity - $used),
                'price_cents' => $garage === null ? $this->rules->garagePriceCents() : null,
            ];
        }

        return $summary;
    }

    /**
     * Buys a garage in a city the level of the driver opens.
     *
     * @throws RuleRefusedException the city does not exist, is closed to the driver, or has their garage already
     */
    public function buy(int $userId, string $region): CityGarage
    {
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $region): CityGarage {
            $this->ledger->lockWallet($userId);

            if (! isset($this->rules->regions()[$region])) {
                throw new RuleRefusedException('city_not_found');
            }

            if ($this->of($userId)->contains('region', $region)) {
                throw new RuleRefusedException('garage_exists');
            }

            $required = $this->rules->cityMinPlayerLevel($region);
            $current = $this->rules->playerLevelAt($this->ledger->xp($userId));

            if ($current < $required) {
                throw new RuleRefusedException('city_locked', ['required' => $required, 'current' => $current]);
            }

            $this->ledger->post($userId, -$this->rules->garagePriceCents(), 'garage', "garage:{$userId}:{$region}");

            return CityGarage::query()->create(['user_id' => $userId, 'region' => $region, 'capacity' => $this->rules->garageCapacity()]);
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * Gives the driver the garage of a city for nothing, which the tutorial does for the first garage of another city (it costs a fortune at the level the
     * city opens, and nothing in the tutorial costs cash). The level of the city is not asked for. A driver who has the garage gets nothing more.
     *
     * @return bool whether a garage was given
     *
     * @throws RuleRefusedException the city does not exist
     */
    public function grant(int $userId, string $region): bool
    {
        if (! isset($this->rules->regions()[$region])) {
            throw new RuleRefusedException('city_not_found');
        }

        if ($this->of($userId)->contains('region', $region)) {
            return false;
        }

        CityGarage::query()->create(['user_id' => $userId, 'region' => $region, 'capacity' => $this->rules->garageCapacity()]);

        return true;
    }

    /**
     * Buys a wreck from the yard into the garage of a city: the car, with the run that drives it on the track of the city, with a full tank.
     * `$requestId` makes a repeated request harmless: a request that was paid already buys nothing again and answers null.
     *
     * @throws RuleRefusedException the car is not sold, the driver has no garage there or it is full
     */
    public function buyCar(int $userId, string $vehicle, string $region, string $requestId, ?int $nowMs = null): ?Car
    {
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $vehicle, $region, $requestId, $nowMs): ?Car {
            $this->ledger->lockWallet($userId);

            $key = "wreck:{$userId}:{$requestId}";

            if (LedgerEntry::query()->where('idem_key', $key)->exists()) {
                return null;
            }

            $price = $this->rules->wreckPriceCents($vehicle);

            if ($price === null || ! isset($this->catalog->startingVehicles()[$vehicle])) {
                throw new RuleRefusedException('wreck_not_for_sale');
            }

            $this->assertRoomIn($userId, $region);

            $this->ledger->post($userId, -$price, 'wreck', $key);

            return $this->park($userId, $vehicle, $region, $nowMs, valueCents: $price);
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * Whether a car can be put in the garage of a city: the city exists, the driver has a garage there and it has a free place.
     *
     * @throws RuleRefusedException `city_not_found`, `no_garage` or `garage_full`
     */
    public function assertRoomIn(int $userId, string $region): void
    {
        if (! isset($this->rules->regions()[$region])) {
            throw new RuleRefusedException('city_not_found');
        }

        if (! $this->of($userId)->contains('region', $region)) {
            throw new RuleRefusedException('no_garage', ['city' => $this->rules->cityName($region)]);
        }

        if (! $this->hasRoomIn($userId, $region)) {
            throw new RuleRefusedException('garage_full', ['city' => $this->rules->cityName($region)]);
        }
    }

    /**
     * Puts a new car in the garage of a city, with the run that drives it on the first track of the city and a full tank. The caller holds
     * the lock of the wallet and has checked the room (`assertRoomIn`) and taken the money.
     *
     * @param  int  $conditionPpm  the condition of the car (1 000 000 is perfect): a worn car breaks down more
     * @param  int|null  $valueCents  what the car is worth, as the driver got it: a sale pays a share of it
     */
    public function park(int $userId, string $vehicle, string $region, ?int $nowMs = null, int $conditionPpm = 1_000_000, ?int $valueCents = null): Car
    {
        $track = $this->rules->tracksOf($region)[0];

        $car = Car::query()->create([
            'user_id' => $userId,
            'vehicle_slug' => $vehicle,
            'level' => $this->rules->carStartLevel(),
            'xp' => $this->rules->carXpToReach($this->rules->carStartLevel()),
            'condition_ppm' => $conditionPpm,
            'value_cents' => $valueCents,
        ]);

        $this->runs->start($userId, $vehicle, $track, $this->inputs->make($vehicle, $track, [], $conditionPpm), $this->inputs->tankMl($vehicle), $nowMs, $car);

        return $car;
    }

    /**
     * The wrecks the yard sells, the cheapest first: the cars of the start whose price the rules give.
     *
     * @return list<array{slug: string, name: string, class: string, price_cents: int}>
     */
    public function yard(): array
    {
        return collect($this->catalog->startingVehicles())
            ->filter(fn (array $vehicle): bool => $this->rules->wreckPriceCents($vehicle['slug']) !== null)
            ->sortBy(fn (array $vehicle): int => $this->rules->wreckPriceCents($vehicle['slug']))
            ->map(fn (array $vehicle): array => [
                'slug' => $vehicle['slug'],
                'name' => I18nText::from($vehicle['name'])->for(),
                'class' => $vehicle['class'],
                'price_cents' => $this->rules->wreckPriceCents($vehicle['slug']),
            ])->values()->all();
    }

    /** Whether a car has a job waiting for the mechanic, or one that is done now: such a car cannot leave the city. */
    public function hasWork(Car $car): bool
    {
        return WorkshopJob::query()->where('car_id', $car->id)->where('status', '!=', WorkshopJob::STATUS_DONE)->exists();
    }
}
