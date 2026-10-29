<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\Car;
use App\Models\CityGarage;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => withoutTireFee());
afterEach(fn () => Carbon::setTestNow());

/** A driver with a car on the track (45 l, at T0) and the experience of a level and some money. */
function fleetDriver(int $level = 1, int $cashCents = 0): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);

    (new Ledger)->ensureWallet($driver->id);
    (new Ledger)->grantXp($driver->id, app(Rules::class)->playerXpToReach($level));

    if ($cashCents > 0) {
        (new Ledger)->post($driver->id, $cashCents, 'gift', 'money:'.$driver->id);
    }

    return [$driver, $run, $run->car];
}

/** Another car of the driver with a run of its own in a city, standing at T0. */
function anotherCar(User $driver, string $track = 'zlomowo-street'): array
{
    $car = Car::factory()->create(['user_id' => $driver->id, 'vehicle_slug' => 'rdzak-gt-4']);
    $run = RaceRun::factory()->create(['user_id' => $driver->id, 'car_id' => $car->id, 'car_slug' => 'rdzak-gt-4', 'track_slug' => $track, 'cursor_ms' => RUN_T0, 'seed' => str_repeat('0', 32)]);

    return [$car, $run];
}

function getCars(User $driver): TestResponse
{
    return test()->getJson('/api/v1/cars', bearerFor($driver));
}

function buyGarage(User $driver, string $city, array $headers = []): TestResponse
{
    return test()->postJson('/api/v1/garages', ['city' => $city], [...bearerFor($driver), ...$headers]);
}

function buyWreck(User $driver, string $vehicle, string $city, string $requestId = 'w1', array $headers = []): TestResponse
{
    return test()->postJson('/api/v1/cars', ['vehicle' => $vehicle, 'city' => $city, 'request_id' => $requestId], [...bearerFor($driver), ...$headers]);
}

function selectCar(User $driver, int $carId): TestResponse
{
    return test()->postJson("/api/v1/cars/{$carId}/select", [], bearerFor($driver));
}

describe('the cars and the garages', function () {
    it('requires a token', function () {
        $this->getJson('/api/v1/cars')->assertUnauthorized();
        $this->postJson('/api/v1/cars', ['vehicle' => 'rdzak-gt-4', 'city' => 'zlomowo', 'request_id' => 'a'])->assertUnauthorized();
        $this->postJson('/api/v1/cars/1/select')->assertUnauthorized();
        $this->postJson('/api/v1/garages', ['city' => 'kruszyn'])->assertUnauthorized();
    });

    it('lists the cars with their city and run, the garages of every city and the wrecks of the yard', function () {
        [$driver, , $car] = fleetDriver(cashCents: 10_000);

        $response = getCars($driver)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.selected', $car->id)
            ->assertJsonPath('wallet.cash_cents', 10_000)
            ->assertJsonCount(1, 'data.cars')
            ->assertJsonCount(5, 'data.garages')
            ->assertJsonPath('data.cars.0.city', ['slug' => 'zlomowo', 'name' => 'Złomowo'])
            ->assertJsonPath('data.cars.0.selected', true)
            ->assertJsonPath('data.cars.0.run.status', 'running')
            // What the carousel of the fleet shows of a body: its kind, its tier, and the picture the pack gives it (none, in the car game).
            ->assertJsonPath('data.cars.0.vehicle', ['slug' => 'rdzak-gt-4', 'name' => 'Rdzak GT-4', 'image_url' => null, 'class' => 'hatchback', 'tier' => 2])
            ->assertJsonPath('data.cars.0.travel', null);

        $garages = collect($response->json('data.garages'))->keyBy('city');

        // The garage of the home city is there from the start, with the car in it; the others are to be bought.
        expect($garages['zlomowo'])->toMatchArray(['owned' => true, 'capacity' => 3, 'used' => 1, 'free' => 2, 'price_cents' => null])
            ->and($garages['kruszyn'])->toMatchArray(['owned' => false, 'used' => 0, 'free' => 0, 'price_cents' => 500_000, 'level_required' => 3, 'open' => false])
            ->and(array_column($response->json('data.yard'), 'price_cents', 'slug'))->toBe(['rdzak-gt-4' => 114_000, 'bizon-m-2' => 139_000, 'kuna-4x4' => 151_000]);
    });
});

describe('buying a garage', function () {
    it('buys a garage in a city that the level of the driver opens, for the price of the rules', function () {
        [$driver] = fleetDriver(level: 3, cashCents: 600_000);

        buyGarage($driver, 'kruszyn')
            ->assertCreated()
            ->assertJsonPath('wallet.cash_cents', 100_000);

        expect(CityGarage::query()->where('user_id', $driver->id)->where('region', 'kruszyn')->first()->capacity)->toBe(3);
    });

    it('refuses a city that is closed, a garage the driver has already, and a price they cannot pay', function () {
        [$driver] = fleetDriver(level: 1, cashCents: 600_000);
        [$poor] = fleetDriver(level: 3, cashCents: 1_000);

        buyGarage($driver, 'kruszyn', ['X-Locale' => 'en'])->assertStatus(422)->assertJsonPath('code', 'city_locked')->assertJsonPath('details', ['required' => 3, 'current' => 1]);
        buyGarage($driver, 'zlomowo')->assertStatus(422)->assertJsonPath('code', 'garage_exists');
        buyGarage($driver, 'atlantis')->assertStatus(422)->assertJsonPath('code', 'city_not_found');
        buyGarage($poor, 'kruszyn')->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');

        expect(CityGarage::query()->where('region', 'kruszyn')->count())->toBe(0);
    });
});

describe('buying a wreck', function () {
    it('buys a wreck from the yard into the garage of a city: a car with a run of its own, a full tank and the track of that city', function () {
        [$driver] = fleetDriver(cashCents: 200_000);

        buyWreck($driver, 'rdzak-gt-4', 'zlomowo')
            ->assertCreated()
            ->assertJsonPath('repeated', false)
            ->assertJsonPath('wallet.cash_cents', 200_000 - 114_000)
            ->assertJsonCount(2, 'data.cars')
            // The car the driver plays does not change by itself.
            ->assertJsonPath('data.cars.0.selected', true)
            ->assertJsonPath('data.cars.1.selected', false)
            ->assertJsonPath('data.cars.1.run.fuel_ml', 45_000)
            ->assertJsonPath('data.cars.1.run.track', 'Złomowo Street Sprint');

        expect(RaceRun::query()->where('user_id', $driver->id)->count())->toBe(2);
    });

    it('buys nothing twice for a request that comes again, and says so', function () {
        [$driver] = fleetDriver(cashCents: 400_000);

        buyWreck($driver, 'rdzak-gt-4', 'zlomowo', 'same')->assertCreated();
        buyWreck($driver, 'rdzak-gt-4', 'zlomowo', 'same')
            ->assertOk()
            ->assertJsonPath('repeated', true)
            ->assertJsonCount(2, 'data.cars')
            ->assertJsonPath('wallet.cash_cents', 400_000 - 114_000);
    });

    it('refuses a car the yard does not sell, a city without the garage of the driver, a full garage and a price they cannot pay', function () {
        [$driver, , $car] = fleetDriver(level: 3, cashCents: 400_000);
        [$poor] = fleetDriver(cashCents: 1_000);
        CityGarage::query()->where('user_id', $driver->id)->where('region', 'zlomowo')->delete();
        CityGarage::query()->create(['user_id' => $driver->id, 'region' => 'zlomowo', 'capacity' => 1]);

        buyWreck($driver, 'volt', 'zlomowo')->assertStatus(422)->assertJsonPath('code', 'wreck_not_for_sale');
        buyWreck($driver, 'rdzak-gt-4', 'kruszyn', 'a', ['X-Locale' => 'en'])->assertStatus(422)->assertJsonPath('code', 'no_garage')->assertJsonPath('message', 'You have no garage in Kruszyn. Buy one there first.');
        // The one place of the garage of the home city has the car of the start in it.
        buyWreck($driver, 'rdzak-gt-4', 'zlomowo', 'b', ['X-Locale' => 'en'])->assertStatus(422)->assertJsonPath('code', 'garage_full')->assertJsonPath('message', 'The garage in Złomowo is full.');
        buyWreck($poor, 'rdzak-gt-4', 'zlomowo')->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');

        expect(RaceRun::query()->where('user_id', $driver->id)->count())->toBe(1)->and($car->id)->toBeInt();
    });
});

describe('the car the driver plays', function () {
    it('shows the run, the garage and the workshop of the selected car from then on', function () {
        [$driver, $run, $first] = fleetDriver();
        [$second, $secondRun] = anotherCar($driver, 'kruszyn-quarry');

        selectCar($driver, $second->id)->assertOk()->assertJsonPath('data.selected', $second->id);

        test()->getJson('/api/v1/runs/current', bearerFor($driver))->assertOk()->assertJsonPath('data.id', $secondRun->id)->assertJsonPath('data.track.slug', 'kruszyn-quarry');
        test()->getJson('/api/v1/garage', bearerFor($driver))->assertOk()->assertJsonPath('data.car.id', $second->id)->assertJsonPath('data.workshop.city', ['slug' => 'kruszyn', 'name' => 'Kruszyn']);

        selectCar($driver, $first->id)->assertOk();

        test()->getJson('/api/v1/runs/current', bearerFor($driver))->assertOk()->assertJsonPath('data.id', $run->id);
    });

    it('does not let the driver play a car that is not theirs', function () {
        [$driver] = fleetDriver();
        [$other] = fleetDriver();
        $theirs = Car::query()->where('user_id', $other->id)->first();

        selectCar($driver, $theirs->id)->assertStatus(422)->assertJsonPath('code', 'car_not_found');
        selectCar($driver, 999_999)->assertStatus(422)->assertJsonPath('code', 'car_not_found');
    });

    it('drives the parked cars by themselves: every car of the driver earns, and the list is current', function () {
        [$driver, $first] = fleetDriver();
        [, $secondRun] = anotherCar($driver);

        clockAt(150_000);
        $response = getCars($driver)->assertOk();

        // Two laps of 75 s at $15.50 for the first; the second drives its own track the same time, and its cash is the same wallet.
        expect($first->fresh()->laps_done)->toBe(2)
            ->and($secondRun->fresh()->laps_done)->toBeGreaterThan(0)
            ->and($response->json('wallet.cash_cents'))->toBeGreaterThan(3_100)
            ->and(collect($response->json('data.cars'))->pluck('run.laps_done')->all())->each->toBeGreaterThan(0);
    });
});

describe('the workshop of a city', function () {
    function stockPart(User $driver, string $slug, string $slot): Part
    {
        return Part::factory()->create(['user_id' => $driver->id, 'part_slug' => $slug, 'slot' => $slot]);
    }

    function queueOn(User $driver, int $partId): TestResponse
    {
        return test()->postJson('/api/v1/garage/jobs', ['kind' => 'mount', 'part' => $partId], bearerFor($driver));
    }

    it('has a queue of its own in every city, so a full queue in one does not stop a job in another', function () {
        [$driver, , $first] = fleetDriver();
        [$second] = anotherCar($driver, 'kruszyn-quarry');

        foreach ([['sport-exhaust-t2', 'exhaust'], ['street-tires-t2', 'tires'], ['sport-suspension-t1', 'suspension'], ['disc-brakes-t2', 'brakes']] as [$slug, $slot]) {
            queueOn($driver, stockPart($driver, $slug, $slot)->id)->assertCreated();
        }

        queueOn($driver, stockPart($driver, 'factory-cooling-t1', 'cooling')->id)->assertStatus(422)->assertJsonPath('code', 'queue_full');

        selectCar($driver, $second->id)->assertOk();

        queueOn($driver, stockPart($driver, 'factory-cooling-t1', 'cooling')->id)
            ->assertCreated()
            ->assertJsonPath('data.workshop.city.slug', 'kruszyn')
            ->assertJsonCount(1, 'data.workshop.jobs')
            ->assertJsonPath('data.workshop.jobs.0.start_ms', RUN_T0);

        // Back in the first city the four jobs are still there, and only they.
        selectCar($driver, $first->id)->assertOk();
        test()->getJson('/api/v1/garage', bearerFor($driver))->assertOk()->assertJsonCount(4, 'data.workshop.jobs')->assertJsonPath('data.workshop.city.slug', 'zlomowo');
    });

    it('gives two cars in one city the one mechanic, one after the other, and stands only the car that is being worked on', function () {
        [$driver, $firstRun, $first] = fleetDriver();
        [$second, $secondRun] = anotherCar($driver);

        $firstJob = queueOn($driver, stockPart($driver, 'street-tires-t2', 'tires')->id)->assertCreated()->json('data.workshop.jobs.0');

        selectCar($driver, $second->id)->assertOk();
        $secondJob = queueOn($driver, stockPart($driver, 'sport-exhaust-t2', 'exhaust')->id)->assertCreated()->json('data.workshop.jobs.1');

        // The mechanic is at the first car, so the job of the second waits for it, and that car keeps driving.
        expect($secondJob['status'])->toBe('queued')
            ->and($secondJob['start_ms'])->toBe($firstJob['finish_ms'])
            ->and($firstRun->fresh()->stop_reason)->toBe(RaceRun::STOP_WORKSHOP)
            ->and($secondRun->fresh()->status)->toBe(RaceRun::STATUS_RUNNING);

        // When the first job is done the first car drives again, and the mechanic goes to the second.
        clockAt($firstJob['finish_ms'] - RUN_T0 + 1_000);
        selectCar($driver, $first->id)->assertOk();

        expect($firstRun->fresh()->status)->toBe(RaceRun::STATUS_RUNNING)
            ->and($secondRun->fresh()->stop_reason)->toBe(RaceRun::STOP_WORKSHOP)
            ->and(Part::query()->where('car_id', $first->id)->where('mounted_slot', 'tires')->exists())->toBeTrue();
    });

    it('does not let a car leave for another city while a job of its workshop waits for it', function () {
        [$driver, , $first] = fleetDriver(level: 3);
        foreach (array_keys(app(Rules::class)->regions()) as $region) {
            CityGarage::query()->firstOrCreate(['user_id' => $driver->id, 'region' => $region], ['capacity' => 3]);
        }
        anotherCar($driver);

        // The mechanic is busy with the second car's job first, so the job of the first car waits and the first car still drives.
        selectCar($driver, Car::query()->where('user_id', $driver->id)->orderByDesc('id')->first()->id);
        queueOn($driver, stockPart($driver, 'street-tires-t2', 'tires')->id)->assertCreated();
        selectCar($driver, $first->id);
        queueOn($driver, stockPart($driver, 'sport-exhaust-t2', 'exhaust')->id)->assertCreated();

        test()->postJson('/api/v1/travel', ['to' => 'kruszyn'], bearerFor($driver))->assertStatus(422)->assertJsonPath('code', 'car_cannot_travel');
    });
});
