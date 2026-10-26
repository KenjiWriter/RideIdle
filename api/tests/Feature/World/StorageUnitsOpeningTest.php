<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\Car;
use App\Models\CityGarage;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\StorageOpening;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

afterEach(fn () => Carbon::setTestNow());

/** A driver with a car on the track (at T0, in Złomowo), the experience of a level and some money. */
function unitDriver(int $level = 3, int $cashCents = 1_000_000): User
{
    [$driver] = driverWithRun(['seed' => str_repeat('0', 32)]);

    (new Ledger)->ensureWallet($driver->id);
    (new Ledger)->grantXp($driver->id, app(Rules::class)->playerXpToReach($level));

    if ($cashCents > 0) {
        (new Ledger)->post($driver->id, $cashCents, 'gift', 'money:'.$driver->id);
    }

    return $driver;
}

function openUnit(User $driver, string $unit = 'small', string $requestId = 'u1', array $headers = []): TestResponse
{
    return test()->postJson("/api/v1/market/storage/{$unit}/open", ['request_id' => $requestId], [...bearerFor($driver), ...$headers]);
}

/** The openings the driver has made so far, as the audit table has them. */
function openingsOf(User $driver, string $unit = 'small'): array
{
    return StorageOpening::query()->where('user_id', $driver->id)->where('unit', $unit)->orderBy('opening_index')->get()->all();
}

/** Writes down that the driver opened this many units of a kind that held nothing worth having, as if they had. */
function openedScrap(User $driver, int $count, string $unit = 'small'): void
{
    for ($index = 0; $index < $count; $index++) {
        StorageOpening::query()->create([
            'user_id' => $driver->id, 'unit' => $unit, 'opening_index' => $index, 'outcome' => 'scrap', 'vehicle_slug' => 'rdzak-gt-4',
            'age_years' => 22, 'mileage_km' => 250_000, 'condition_ppm' => 100_000, 'value_cents' => 20_000, 'price_cents' => 120_000,
        ]);
    }
}

describe('the storage units on the market', function () {
    it('requires a token', function () {
        $this->postJson('/api/v1/market/storage/small/open', ['request_id' => 'a'])->assertUnauthorized();
    });

    it('shows the kinds of unit with their price, their odds and the level that opens them', function () {
        $driver = unitDriver(level: 3);

        $response = test()->getJson('/api/v1/market', bearerFor($driver))->assertOk()->assertJsonPath('data.storage.pity_every', 15);
        $units = collect($response->json('data.storage.units'))->keyBy('key');

        expect($units->keys()->all())->toBe(['small', 'medium', 'large'])
            ->and($units['small'])->toMatchArray(['unlock_level' => 3, 'open' => true, 'price_cents' => 120_000, 'until_guarantee' => 15])
            ->and($units['small']['odds'])->toEqual(['scrap' => 70, 'junk' => 25, 'good' => 4.5, 'gem' => 0.5])
            ->and($units['medium'])->toMatchArray(['unlock_level' => 6, 'open' => false, 'price_cents' => 350_000])
            ->and($units['medium']['odds'])->toEqual(['scrap' => 35, 'junk' => 39, 'good' => 20, 'gem' => 6])
            ->and($units['large'])->toMatchArray(['unlock_level' => 9, 'open' => false, 'price_cents' => 600_000])
            ->and($units['large']['odds'])->toEqual(['scrap' => 15, 'junk' => 30, 'good' => 40, 'gem' => 15]);
    });

    it('counts down to the guarantee with every unit that gave nothing good', function () {
        $driver = unitDriver();
        openedScrap($driver, 14);

        $units = collect(test()->getJson('/api/v1/market', bearerFor($driver))->json('data.storage.units'))->keyBy('key');

        expect($units['small']['until_guarantee'])->toBe(1)->and($units['medium']['until_guarantee'])->toBe(15);
    });
});

describe('opening a storage unit', function () {
    it('takes the price and puts the car that was in it in the garage of the city', function () {
        $driver = unitDriver();

        $response = openUnit($driver)->assertCreated()
            ->assertJsonPath('repeated', false)
            ->assertJsonPath('wallet.cash_cents', 1_000_000 - 120_000)
            ->assertJsonPath('data.garage.used', 2);

        $car = Car::query()->findOrFail($response->json('bought'));
        $opened = $response->json('opened');

        expect($opened['class'])->toBeIn(['scrap', 'junk', 'good', 'gem'])
            ->and($opened['vehicle']['slug'])->toBe($car->vehicle_slug)
            ->and($opened['vehicle'])->toHaveKeys(['name', 'class', 'power_kw', 'top_speed_kmh', 'mass_kg'])
            ->and($opened)->toHaveKeys(['age_years', 'mileage_km', 'condition', 'value_cents', 'sale_cents'])
            ->and($car->user_id)->toBe($driver->id)
            ->and(abs($car->conditionPpm() - $opened['condition']['percent'] * 10_000))->toBeLessThanOrEqual(5_000)
            ->and($car->value_cents)->toBe($opened['value_cents'])
            ->and(RaceRun::query()->where('car_id', $car->id)->exists())->toBeTrue();
    });

    it('says what a sale of the car pays, and the list of the fleet says the same', function () {
        $driver = unitDriver();

        $response = openUnit($driver)->assertCreated();
        $bought = $response->json('bought');

        $listed = collect(test()->getJson('/api/v1/cars', bearerFor($driver))->json('data.cars'))->firstWhere('id', $bought);

        expect($listed['sale_cents'])->toBe($response->json('opened.sale_cents'))
            // It is never less than the scrap of the mass of the car, and a unit never gives money away.
            ->and($response->json('opened.sale_cents'))->toBeGreaterThanOrEqual($response->json('opened.vehicle.mass_kg') * 25);
    });

    it('writes down the draw: what came out, for how much, and which car it became', function () {
        $driver = unitDriver();

        $response = openUnit($driver)->assertCreated();

        $rows = openingsOf($driver);

        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray([
                'opening_index' => 0, 'outcome' => $response->json('opened.class'), 'vehicle_slug' => $response->json('opened.vehicle.slug'),
                'value_cents' => $response->json('opened.value_cents'), 'price_cents' => 120_000, 'car_id' => $response->json('bought'),
            ]);

        openUnit($driver, requestId: 'u2')->assertCreated();

        expect(array_map(fn (StorageOpening $row): int => $row->opening_index, openingsOf($driver)))->toBe([0, 1]);
    });

    it('opens nothing twice for a request that comes again, and says so', function () {
        $driver = unitDriver();

        openUnit($driver, requestId: 'same')->assertCreated();
        openUnit($driver, requestId: 'same')->assertOk()->assertJsonPath('repeated', true)->assertJsonPath('opened', null)->assertJsonPath('wallet.cash_cents', 1_000_000 - 120_000);

        expect(openingsOf($driver))->toHaveCount(1)
            ->and(Car::query()->where('user_id', $driver->id)->count())->toBe(2)
            ->and(LedgerEntry::query()->where('idem_key', "storage:{$driver->id}:same")->count())->toBe(1);
    });

    it('draws a unit of its own for each driver', function () {
        $driver = unitDriver();
        $other = unitDriver();

        // The seed is made of the secret of the server, the driver, the kind of unit and the number of the opening, so two drivers differ and a driver cannot fish.
        $one = openUnit($driver, requestId: 'a')->assertCreated()->json('opened');
        $again = openUnit($other, requestId: 'b')->assertCreated()->json('opened');

        expect([$one['vehicle']['slug'], $one['age_years'], $one['mileage_km'], $one['value_cents']])
            ->not->toBe([$again['vehicle']['slug'], $again['age_years'], $again['mileage_km'], $again['value_cents']]);
    });

    it('guarantees a good car or better after fifteen units that gave nothing good', function () {
        $driver = unitDriver();
        openedScrap($driver, 15);

        $class = openUnit($driver)->assertCreated()->json('opened.class');

        expect($class)->toBeIn(['good', 'gem']);

        // Having got it, the count begins again.
        $units = collect(test()->getJson('/api/v1/market', bearerFor($driver))->json('data.storage.units'))->keyBy('key');

        expect($units['small']['until_guarantee'])->toBe(15);
    });

    it('asks for the level of the unit, and says what is missing', function () {
        $driver = unitDriver(level: 2);

        openUnit($driver, 'small', 'a', ['X-Locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unit_level_required')
            ->assertJsonPath('details', ['required' => 3, 'current' => 2])
            ->assertJsonPath('message', 'Requires player level 3 (you are level 2). You get XP by driving.');

        $driver = unitDriver(level: 5);
        openUnit($driver, 'medium', 'b')->assertStatus(422)->assertJsonPath('code', 'unit_level_required');

        expect(StorageOpening::query()->count())->toBe(0);
    });

    it('does not open a unit that does not exist', function () {
        $driver = unitDriver();

        openUnit($driver, 'huge')->assertStatus(422)->assertJsonPath('code', 'unit_not_found');
    });

    it('refuses what the driver cannot pay or has no place for, and takes nothing then', function () {
        $poor = unitDriver(cashCents: 100_000);

        openUnit($poor)->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');

        $driver = unitDriver();
        CityGarage::query()->where('user_id', $driver->id)->where('region', 'zlomowo')->delete();
        CityGarage::query()->create(['user_id' => $driver->id, 'region' => 'zlomowo', 'capacity' => 1]);

        openUnit($driver)->assertStatus(422)->assertJsonPath('code', 'garage_full');

        expect(StorageOpening::query()->count())->toBe(0)
            ->and((new Ledger)->balance($driver->id))->toBe(1_000_000)
            ->and((new Ledger)->balance($poor->id))->toBe(100_000);
    });

    it('validates the request', function () {
        $driver = unitDriver();

        test()->postJson('/api/v1/market/storage/small/open', [], bearerFor($driver))->assertStatus(422);
    });
});
