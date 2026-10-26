<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\Car;
use App\Models\LedgerEntry;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

afterEach(fn () => Carbon::setTestNow());

/** A driver with a car on the track (a Rdzak GT-4, 1000 kg, at T0) and some money. */
function salesDriver(int $cashCents = 0, int $level = 1): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);

    (new Ledger)->ensureWallet($driver->id);
    (new Ledger)->grantXp($driver->id, app(Rules::class)->playerXpToReach($level));

    if ($cashCents > 0) {
        (new Ledger)->post($driver->id, $cashCents, 'gift', 'money:'.$driver->id);
    }

    return [$driver, $run, $run->car];
}

/** Another car of the driver, with a run of its own that stands at T0, worth this much (null: a car whose value was never written down). */
function spareCar(User $driver, ?int $valueCents = null, array $run = []): Car
{
    $car = Car::factory()->create(['user_id' => $driver->id, 'vehicle_slug' => 'rdzak-gt-4', 'value_cents' => $valueCents]);

    RaceRun::factory()->create([...[
        'user_id' => $driver->id, 'car_id' => $car->id, 'car_slug' => 'rdzak-gt-4', 'track_slug' => 'zlomowo-street', 'cursor_ms' => RUN_T0, 'seed' => str_repeat('0', 32),
    ], ...$run]);

    return $car;
}

function sellCar(User $driver, int $carId): TestResponse
{
    return test()->postJson("/api/v1/cars/{$carId}/sell", [], bearerFor($driver));
}

function walletOf(User $driver): int
{
    return (new Ledger)->balance($driver->id);
}

function carsOf(User $driver): TestResponse
{
    return test()->getJson('/api/v1/cars', bearerFor($driver));
}

/** The cars of the list, by their id. */
function carListOf(User $driver): array
{
    return collect(carsOf($driver)->assertOk()->json('data.cars'))->keyBy('id')->all();
}

describe('selling a car', function () {
    it('requires a token', function () {
        $this->postJson('/api/v1/cars/1/sell')->assertUnauthorized();
    });

    it('pays the share of what the car was worth when the driver got it, and takes it out of the garage', function () {
        [$driver] = salesDriver();
        $spare = spareCar($driver, valueCents: 1_000_000);

        sellCar($driver, $spare->id)->assertOk()
            ->assertJsonPath('sold', ['car' => $spare->id, 'cents' => 700_000])
            ->assertJsonPath('wallet.cash_cents', 700_000)
            ->assertJsonCount(1, 'data.cars');

        expect(walletOf($driver))->toBe(700_000)
            ->and(Car::query()->where('user_id', $driver->id)->pluck('id')->all())->not->toContain($spare->id)
            ->and(Car::query()->withoutGlobalScopes()->find($spare->id)->sold_at)->not->toBeNull()
            ->and(RaceRun::query()->withoutGlobalScopes()->where('car_id', $spare->id)->value('stop_reason'))->toBe(RaceRun::STOP_SOLD);
    });

    it('never pays less than the scrap of the mass of the car', function () {
        [$driver] = salesDriver();
        // 70% of $100 is $70, and a thousand kilos of scrap are $250.
        $spare = spareCar($driver, valueCents: 10_000);

        sellCar($driver, $spare->id)->assertOk()->assertJsonPath('sold.cents', 25_000);
    });

    it('prices a car whose value was not written down by what the yard asks for it', function () {
        [$driver] = salesDriver();
        $spare = spareCar($driver);

        // A Rdzak GT-4 is $1,140 in the yard.
        expect(carListOf($driver)[$spare->id]['sale_cents'])->toBe(79_800);

        sellCar($driver, $spare->id)->assertOk()->assertJsonPath('sold.cents', 79_800);
    });

    it('says in the list what a sale pays and why there is none', function () {
        [$driver, , $first] = salesDriver();

        // The only car of the driver is not sold.
        expect(carListOf($driver)[$first->id])->toMatchArray(['sale_cents' => 79_800, 'sell_blocked' => 'last_car']);

        $free = spareCar($driver, valueCents: 500_000);
        $travelling = spareCar($driver, run: ['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => RaceRun::STOP_TRAVEL]);

        $cars = carListOf($driver);

        expect($cars[$first->id]['sell_blocked'])->toBeNull()
            ->and($cars[$free->id])->toMatchArray(['sale_cents' => 350_000, 'sell_blocked' => null])
            ->and($cars[$travelling->id]['sell_blocked'])->toBe('busy');
    });

    it('keeps the last car of the driver', function () {
        [$driver, , $car] = salesDriver();

        sellCar($driver, $car->id)->assertStatus(422)->assertJsonPath('code', 'last_car');

        expect(Car::query()->where('user_id', $driver->id)->count())->toBe(1)->and(walletOf($driver))->toBe(0);
    });

    it('does not sell a car that is on the road or has work waiting in the workshop', function () {
        [$driver] = salesDriver();
        $travelling = spareCar($driver, run: ['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => RaceRun::STOP_TRAVEL]);
        $inWorkshop = spareCar($driver);
        // The job ends in ten minutes: a job that is due is carried out before the sale, and then the car is free.
        WorkshopJob::factory()->create(['user_id' => $driver->id, 'car_id' => $inWorkshop->id, 'start_ms' => RUN_T0, 'finish_ms' => RUN_T0 + 600_000]);

        sellCar($driver, $travelling->id)->assertStatus(422)->assertJsonPath('code', 'car_busy');
        sellCar($driver, $inWorkshop->id)->assertStatus(422)->assertJsonPath('code', 'car_busy');

        expect(Car::query()->where('user_id', $driver->id)->count())->toBe(3)->and(walletOf($driver))->toBe(0);
    });

    it('gives the parts that sat on the car back to the inventory', function () {
        [$driver] = salesDriver();
        $spare = spareCar($driver, valueCents: 400_000);
        $part = Part::factory()->state(['part_slug' => 'sport-exhaust-t2', 'slot' => 'exhaust'])->mountedOn($spare)->create();

        sellCar($driver, $spare->id)->assertOk();

        $part->refresh();

        expect($part->user_id)->toBe($driver->id)->and($part->car_id)->toBeNull()->and($part->mounted_slot)->toBeNull();
    });

    it('pays what the car earned until now before it goes', function () {
        [$driver] = salesDriver();
        $spare = spareCar($driver, valueCents: 1_000_000);

        // The spare car was driving from T0; two laps of 75 s, $15.50 each, are behind it.
        clockAt(150_000);

        sellCar($driver, $spare->id)->assertOk();

        expect(walletOf($driver))->toBeGreaterThanOrEqual(700_000 + 1_550);
    });

    it('lets go of the selection when the car that is played is sold', function () {
        [$driver] = salesDriver();
        $spare = spareCar($driver, valueCents: 600_000);
        test()->postJson("/api/v1/cars/{$spare->id}/select", [], bearerFor($driver))->assertOk();

        sellCar($driver, $spare->id)->assertOk();

        expect($driver->refresh()->selected_car_id)->toBeNull();
        carsOf($driver)->assertOk()->assertJsonCount(1, 'data.cars');
    });

    it('pays once, however many times it is asked', function () {
        [$driver] = salesDriver();
        $spare = spareCar($driver, valueCents: 1_000_000);

        sellCar($driver, $spare->id)->assertOk();
        sellCar($driver, $spare->id)->assertStatus(422)->assertJsonPath('code', 'car_not_found');

        expect(walletOf($driver))->toBe(700_000)
            ->and(LedgerEntry::query()->where('idem_key', "sale:{$driver->id}:{$spare->id}")->count())->toBe(1);
    });

    it('does not sell the car of another driver, or one that does not exist', function () {
        [$driver] = salesDriver();
        [$other] = salesDriver();
        $theirs = spareCar($other, valueCents: 1_000_000);

        sellCar($driver, $theirs->id)->assertStatus(422)->assertJsonPath('code', 'car_not_found');
        sellCar($driver, 999_999)->assertStatus(422)->assertJsonPath('code', 'car_not_found');

        expect(Car::query()->find($theirs->id))->not->toBeNull()->and(walletOf($driver))->toBe(0);
    });

    it('refuses in the language of the driver', function () {
        [$driver, , $car] = salesDriver();

        test()->postJson("/api/v1/cars/{$car->id}/sell", [], [...bearerFor($driver), 'X-Locale' => 'pl'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nie możesz sprzedać ostatniego auta.');
    });
});

describe('what a car is worth to a sale', function () {
    it('is the price the driver paid on the exchange', function () {
        [$driver] = salesDriver(cashCents: 20_000_000, level: 9);
        $listing = test()->getJson('/api/v1/market', bearerFor($driver))->json('data.exchange.listings.0');

        $car = Car::query()->findOrFail(test()->postJson("/api/v1/market/exchange/{$listing['key']}/buy", [], bearerFor($driver))->assertCreated()->json('bought'));

        expect($car->value_cents)->toBe($listing['price_cents']);
    });

    it('is the price paid at the dealer', function () {
        [$driver] = salesDriver(cashCents: 5_000_000);

        $bought = test()->postJson('/api/v1/market/dealer', ['vehicle' => 'rdzak-gt-2', 'request_id' => 'd1'], bearerFor($driver))->assertCreated();
        $car = Car::query()->findOrFail($bought->json('bought'));

        expect($car->value_cents)->toBe(1_550_000);
    });

    it('is the price paid for a wreck of the yard', function () {
        [$driver] = salesDriver(cashCents: 1_000_000);

        $bought = test()->postJson('/api/v1/cars', ['vehicle' => 'bizon-m-2', 'city' => 'zlomowo', 'request_id' => 'w1'], bearerFor($driver))->assertCreated();
        $wreck = Car::query()->findOrFail($bought->json('bought'));

        expect($wreck->value_cents)->toBe(139_000);

        // Buying a wreck and selling it again loses money.
        sellCar($driver, $wreck->id)->assertOk();
        expect(walletOf($driver))->toBeLessThan(1_000_000);
    });
});

it('values the first car of a driver as the yard does', function () {
    $user = User::factory()->guest()->create();

    $run = runService()->start($user->id, 'rdzak-gt-4', 'zlomowo-street', dryInputs(), 45_000, 0);

    expect($run->car->value_cents)->toBe(114_000);
});
