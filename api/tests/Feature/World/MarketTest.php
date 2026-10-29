<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\Car;
use App\Models\CityGarage;
use App\Models\RaceRun;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

afterEach(fn () => Carbon::setTestNow());

/** A driver with a car on the track (at T0, a refresh of the exchange begins there), the experience of a level and some money. */
function marketDriver(int $level = 1, int $cashCents = 0): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);

    (new Ledger)->ensureWallet($driver->id);
    (new Ledger)->grantXp($driver->id, app(Rules::class)->playerXpToReach($level));

    if ($cashCents > 0) {
        (new Ledger)->post($driver->id, $cashCents, 'gift', 'money:'.$driver->id);
    }

    return [$driver, $run, $run->car];
}

function getMarket(User $driver): TestResponse
{
    return test()->getJson('/api/v1/market', bearerFor($driver));
}

function inspectListing(User $driver, string $key): TestResponse
{
    return test()->postJson("/api/v1/market/exchange/{$key}/inspect", [], bearerFor($driver));
}

function buyListing(User $driver, string $key, array $headers = []): TestResponse
{
    return test()->postJson("/api/v1/market/exchange/{$key}/buy", [], [...bearerFor($driver), ...$headers]);
}

function buyFromDealer(User $driver, string $vehicle, string $requestId = 'd1', array $headers = []): TestResponse
{
    return test()->postJson('/api/v1/market/dealer', ['vehicle' => $vehicle, 'request_id' => $requestId], [...bearerFor($driver), ...$headers]);
}

/** The listings on the exchange now, by their place. */
function listingsOf(User $driver): array
{
    return getMarket($driver)->json('data.exchange.listings');
}

describe('the market', function () {
    it('requires a token', function () {
        $this->getJson('/api/v1/market')->assertUnauthorized();
        $this->postJson('/api/v1/market/exchange/zlomowo.1.0.deadbeef/inspect')->assertUnauthorized();
        $this->postJson('/api/v1/market/exchange/zlomowo.1.0.deadbeef/buy')->assertUnauthorized();
        $this->postJson('/api/v1/market/dealer', ['vehicle' => 'rdzak-gt-2', 'request_id' => 'a'])->assertUnauthorized();
    });

    it('shows the market of the city where the car stands, with its garage, the exchange, the dealer and the wrecks', function () {
        [$driver] = marketDriver(level: 3, cashCents: 10_000);

        $response = getMarket($driver)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.city', ['slug' => 'zlomowo', 'name' => 'Złomowo'])
            ->assertJsonPath('data.level', 3)
            ->assertJsonPath('data.garage', ['owned' => true, 'capacity' => 3, 'used' => 1, 'free' => 2])
            ->assertJsonPath('wallet.cash_cents', 10_000)
            ->assertJsonCount(4, 'data.exchange.listings')
            ->assertJsonCount(3, 'data.wrecks');

        // The refresh is half an hour long, and this one began at T0.
        expect($response->json('data.exchange.refresh_ms'))->toBe(1_800_000)
            ->and($response->json('data.exchange.next_refresh_ms'))->toBe(RUN_T0 + 1_800_000)
            ->and($response->json('now_ms'))->toBe(RUN_T0);

        // The dealer sells the cars that have it among their sources, the lowest level first, and says which ones the level opens.
        $dealer = collect($response->json('data.dealer'));

        expect($dealer->pluck('vehicle.slug')->all())->toBe([
            'rdzak-gt-2', 'rdzak-gt-3', 'furgon-z-1', 'rdzak-gt-5', 'furgon-z-3', 'liman-2-0', 'kornet-v6',
            // The cars above the fourth tier, by the level they ask and then by the price: 12, 15, 18, 21 and 27.
            'rdzak-rs', 'bizon-v8', 'zubr-r6', 'furgon-z-5', 'kornet-r6', 'liman-3-0', 'serwal-gt', 'zubr-v8', 'bizon-ss', 'kornet-gt', 'grot-q8', 'serwal-v10', 'orzel-v12',
        ])
            ->and($dealer->pluck('open')->all())->toBe([true, true, true, ...array_fill(0, 17, false)])
            ->and($dealer->firstWhere('vehicle.slug', 'kornet-v6'))->toMatchArray(['price_cents' => 3_000_000, 'level_required' => 9])
            ->and($dealer->first()['vehicle'])->toMatchArray(['name' => 'Rdzak GT-2', 'class' => 'hatchback', 'tier' => 1, 'image_url' => null, 'drivetrain' => 'fwd', 'engine' => 'R4 1.3', 'power_kw' => 65, 'top_speed_kmh' => 150, 'mass_kg' => 880]);
    });

    it('lists only the cars the level of the driver opens, and none of the condition', function () {
        [$low] = marketDriver(level: 1);
        [$high] = marketDriver(level: 9);

        $slugs = fn (User $driver): array => collect(listingsOf($driver))->pluck('vehicle.slug')->unique()->sort()->values()->all();

        // At level 1 only the cheapest car of the pack is sold on the exchange, and at level 9 the whole of it.
        expect($slugs($low))->toBe(['rdzak-gt-2']);

        $listing = listingsOf($high)[0];

        expect($listing)->toHaveKeys(['key', 'vehicle', 'age_years', 'mileage_km', 'word', 'price_cents', 'inspected', 'inspection_cost_cents', 'condition'])
            ->and($listing['inspected'])->toBeFalse()
            ->and($listing['condition'])->toBeNull()
            ->and($listing['word'])->toBeIn(['good', 'fair', 'poor'])
            // An inspection costs 3% of the price, in whole dollars.
            ->and($listing['inspection_cost_cents'])->toBe((int) (round($listing['price_cents'] * 0.03 / 100) * 100));
    });

    it('lists the same cars until the exchange is refreshed, and other ones after', function () {
        [$driver] = marketDriver(level: 9);

        $first = collect(listingsOf($driver))->pluck('key')->all();

        clockAt(1_799_000);
        expect(collect(listingsOf($driver))->pluck('key')->all())->toBe($first);

        clockAt(1_800_000);
        $after = collect(listingsOf($driver))->pluck('key')->all();

        expect($after)->not->toBe($first)->and($after)->toHaveCount(4);
    });

    it('gives each driver an exchange of their own', function () {
        [$one] = marketDriver(level: 9);
        [$other] = marketDriver(level: 9);

        expect(collect(listingsOf($one))->pluck('key')->all())->not->toBe(collect(listingsOf($other))->pluck('key')->all());
    });
});

describe('inspecting a car of the exchange', function () {
    it('shows its condition for a share of its price, once', function () {
        [$driver] = marketDriver(level: 9, cashCents: 100_000);
        $listing = listingsOf($driver)[0];

        $response = inspectListing($driver, $listing['key'])->assertOk();
        $inspected = $response->json('data.exchange.listings.0');

        expect($inspected['key'])->toBe($listing['key'])
            ->and($inspected['inspected'])->toBeTrue()
            ->and($inspected['inspection_cost_cents'])->toBeNull()
            ->and($inspected['condition']['percent'])->toBeBetween(5, 100)
            ->and($inspected['condition']['defects'])->toBeInt()
            ->and($response->json('wallet.cash_cents'))->toBe(100_000 - $listing['inspection_cost_cents']);

        // Asking again is free and changes nothing.
        inspectListing($driver, $listing['key'])->assertOk()->assertJsonPath('wallet.cash_cents', 100_000 - $listing['inspection_cost_cents']);
    });

    it('is refused for a driver who cannot pay, and for a car that is gone', function () {
        [$driver] = marketDriver(level: 9, cashCents: 100);
        $listing = listingsOf($driver)[0];

        inspectListing($driver, $listing['key'])->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');
        inspectListing($driver, 'zlomowo.1.0.deadbeef')->assertStatus(422)->assertJsonPath('code', 'listing_gone');
        inspectListing($driver, 'nonsense')->assertStatus(422)->assertJsonPath('code', 'listing_gone');
    });

    it('is refused after the exchange was refreshed', function () {
        [$driver] = marketDriver(level: 9, cashCents: 100_000);
        $listing = listingsOf($driver)[0];

        clockAt(1_800_000);

        inspectListing($driver, $listing['key'])->assertStatus(422)->assertJsonPath('code', 'listing_gone');
    });

    it('does not show the condition of a car to another driver', function () {
        [$driver] = marketDriver(level: 9, cashCents: 100_000);
        [$other] = marketDriver(level: 9, cashCents: 100_000);
        $listing = listingsOf($driver)[0];

        inspectListing($other, $listing['key'])->assertStatus(422)->assertJsonPath('code', 'listing_gone');
    });
});

describe('buying a car of the exchange', function () {
    it('puts the car in the garage of the city, in the condition the inspection showed, and takes the price', function () {
        [$driver] = marketDriver(level: 9, cashCents: 5_000_000);
        $listing = listingsOf($driver)[0];
        $condition = inspectListing($driver, $listing['key'])->json('data.exchange.listings.0.condition');
        $cash = 5_000_000 - $listing['inspection_cost_cents'];

        $response = buyListing($driver, $listing['key'])->assertCreated()
            ->assertJsonPath('repeated', false)
            ->assertJsonPath('wallet.cash_cents', $cash - $listing['price_cents'])
            ->assertJsonPath('data.garage.used', 2);

        $car = Car::query()->findOrFail($response->json('bought'));

        // The car is the one that was listed, with a run of its own on the track of the city, a full tank, and the condition that was inspected.
        expect($car->vehicle_slug)->toBe($listing['vehicle']['slug'])
            ->and((int) round($car->condition_ppm / 10_000))->toBe($condition['percent'])
            ->and($car->user_id)->toBe($driver->id)
            ->and(RaceRun::query()->where('car_id', $car->id)->value('track_slug'))->toBe('zlomowo-street');

        // It is not listed any more, and it shows in the fleet with its condition. The driver still plays the car they played.
        expect(collect($response->json('data.exchange.listings'))->pluck('key')->all())->not->toContain($listing['key']);

        $fleet = test()->getJson('/api/v1/cars', bearerFor($driver))->assertOk();
        $bought = collect($fleet->json('data.cars'))->firstWhere('id', $car->id);

        expect($bought['condition_percent'])->toBe($condition['percent'])
            ->and($bought['selected'])->toBeFalse();
    });

    it('buys a car nobody has inspected: the condition is a surprise, and it is the one that was listed', function () {
        [$driver] = marketDriver(level: 9, cashCents: 5_000_000);
        $listing = listingsOf($driver)[1];

        $bought = buyListing($driver, $listing['key'])->assertCreated()->json('bought');

        // What an inspection would have said is what the car is: asking about a car that is bought is the same as asking before.
        expect(Car::query()->findOrFail($bought)->condition_ppm)->toBeBetween(50_000, 1_000_000);
    });

    it('buys a car once, and a repeated request says so', function () {
        [$driver] = marketDriver(level: 9, cashCents: 5_000_000);
        $listing = listingsOf($driver)[0];

        buyListing($driver, $listing['key'])->assertCreated();
        buyListing($driver, $listing['key'])
            ->assertOk()
            ->assertJsonPath('repeated', true)
            ->assertJsonPath('bought', null)
            ->assertJsonPath('wallet.cash_cents', 5_000_000 - $listing['price_cents']);

        expect(Car::query()->where('user_id', $driver->id)->count())->toBe(2);
    });

    it('is refused for a car that is gone, a price the driver cannot pay, and a garage with no place', function () {
        [$driver] = marketDriver(level: 9, cashCents: 100);
        $listing = listingsOf($driver)[0];

        buyListing($driver, $listing['key'])->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');
        buyListing($driver, 'zlomowo.1.0.deadbeef')->assertStatus(422)->assertJsonPath('code', 'listing_gone');

        (new Ledger)->post($driver->id, 5_000_000, 'gift', 'more');
        CityGarage::query()->where('user_id', $driver->id)->where('region', 'zlomowo')->delete();
        CityGarage::query()->create(['user_id' => $driver->id, 'region' => 'zlomowo', 'capacity' => 1]);

        buyListing($driver, $listing['key'], ['X-Locale' => 'en'])->assertStatus(422)->assertJsonPath('code', 'garage_full')->assertJsonPath('message', 'The garage in Złomowo is full.');

        expect(Car::query()->where('user_id', $driver->id)->count())->toBe(1);
    });

    it('is refused after the exchange was refreshed', function () {
        [$driver] = marketDriver(level: 9, cashCents: 5_000_000);
        $listing = listingsOf($driver)[0];

        clockAt(1_800_000);

        buyListing($driver, $listing['key'])->assertStatus(422)->assertJsonPath('code', 'listing_gone');
    });

    it('does not buy a car the driver did not see when a level changes what the places of the exchange hold', function () {
        [$driver] = marketDriver(level: 1, cashCents: 5_000_000);
        $before = listingsOf($driver);

        (new Ledger)->grantXp($driver->id, app(Rules::class)->playerXpToReach(9));
        $after = collect(listingsOf($driver))->pluck('key')->all();

        // The places that now hold another car have another key, and the key from before buys nothing.
        $changed = collect($before)->first(fn (array $listing): bool => ! in_array($listing['key'], $after, true));

        expect($changed)->not->toBeNull();

        buyListing($driver, $changed['key'])->assertStatus(422)->assertJsonPath('code', 'listing_gone');
        expect(Car::query()->where('user_id', $driver->id)->count())->toBe(1);
    });

    it('goes to the garage of the city where the car the driver plays stands, and cars of another city are not listed', function () {
        [$driver] = marketDriver(level: 9, cashCents: 5_000_000);
        $zlomowo = listingsOf($driver)[0];

        CityGarage::query()->create(['user_id' => $driver->id, 'region' => 'kruszyn', 'capacity' => 3]);
        $car = Car::query()->create(['user_id' => $driver->id, 'vehicle_slug' => 'rdzak-gt-4', 'level' => 3, 'xp' => 600]);
        RaceRun::factory()->create(['user_id' => $driver->id, 'car_id' => $car->id, 'car_slug' => 'rdzak-gt-4', 'track_slug' => 'kruszyn-quarry', 'cursor_ms' => RUN_T0, 'seed' => str_repeat('0', 32)]);
        test()->postJson("/api/v1/cars/{$car->id}/select", [], bearerFor($driver))->assertOk();

        getMarket($driver)->assertJsonPath('data.city.slug', 'kruszyn')->assertJsonPath('data.garage.used', 1);

        // The listing of Złomowo is gone for a driver in Kruszyn, and what they buy now stands in Kruszyn.
        buyListing($driver, $zlomowo['key'])->assertStatus(422)->assertJsonPath('code', 'listing_gone');

        $kruszyn = listingsOf($driver)[0];
        $bought = buyListing($driver, $kruszyn['key'])->assertCreated()->assertJsonPath('data.garage.used', 2)->json('bought');

        expect(RaceRun::query()->where('car_id', $bought)->value('track_slug'))->toBe('kruszyn-quarry');
    });
});

describe('buying a new car from the dealer', function () {
    it('puts a car in a perfect condition in the garage of the city, for its price', function () {
        [$driver] = marketDriver(level: 1, cashCents: 2_000_000);

        $response = buyFromDealer($driver, 'rdzak-gt-2')->assertCreated()
            ->assertJsonPath('repeated', false)
            ->assertJsonPath('wallet.cash_cents', 2_000_000 - 1_550_000)
            ->assertJsonPath('data.garage.used', 2);

        $car = Car::query()->findOrFail($response->json('bought'));

        expect($car->vehicle_slug)->toBe('rdzak-gt-2')
            ->and($car->conditionPpm())->toBe(1_000_000)
            ->and(RaceRun::query()->where('car_id', $car->id)->exists())->toBeTrue();
    });

    it('buys nothing twice for a request that comes again, and says so', function () {
        [$driver] = marketDriver(level: 1, cashCents: 5_000_000);

        buyFromDealer($driver, 'rdzak-gt-2', 'same')->assertCreated();
        buyFromDealer($driver, 'rdzak-gt-2', 'same')->assertOk()->assertJsonPath('repeated', true)->assertJsonPath('wallet.cash_cents', 5_000_000 - 1_550_000);
        // Another request is another car.
        buyFromDealer($driver, 'rdzak-gt-2', 'other')->assertCreated();

        expect(Car::query()->where('user_id', $driver->id)->count())->toBe(3);
    });

    it('asks for the level of the car, and says what is missing', function () {
        [$driver] = marketDriver(level: 1, cashCents: 9_000_000);

        buyFromDealer($driver, 'kornet-v6', 'a', ['X-Locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'vehicle_level_required')
            ->assertJsonPath('details', ['required' => 9, 'current' => 1])
            ->assertJsonPath('message', 'Requires player level 9 (you are level 1). You get XP by driving.');

        expect(Car::query()->where('user_id', $driver->id)->count())->toBe(1);
    });

    it('does not sell a car the dealer does not have, and refuses what the driver cannot pay or has no place for', function () {
        [$driver] = marketDriver(level: 9, cashCents: 100_000);

        buyFromDealer($driver, 'rdzak-gt-4', 'a')->assertStatus(422)->assertJsonPath('code', 'vehicle_not_for_sale');
        buyFromDealer($driver, 'does-not-exist', 'b')->assertStatus(422)->assertJsonPath('code', 'vehicle_not_for_sale');
        buyFromDealer($driver, 'kornet-v6', 'c')->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');

        (new Ledger)->post($driver->id, 5_000_000, 'gift', 'more');
        CityGarage::query()->where('user_id', $driver->id)->where('region', 'zlomowo')->delete();
        CityGarage::query()->create(['user_id' => $driver->id, 'region' => 'zlomowo', 'capacity' => 1]);

        buyFromDealer($driver, 'kornet-v6', 'd')->assertStatus(422)->assertJsonPath('code', 'garage_full');
    });

    it('validates the request', function () {
        [$driver] = marketDriver();

        test()->postJson('/api/v1/market/dealer', [], bearerFor($driver))->assertStatus(422);
        test()->postJson('/api/v1/market/dealer', ['vehicle' => 'rdzak-gt-2'], bearerFor($driver))->assertStatus(422);
    });
});
