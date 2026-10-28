<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\CityGarage;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\Travel;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

afterEach(fn () => Carbon::setTestNow());

// The stock Rdzak drives 175 km/h at best, and a journey takes half of that on average: 38 km to Kruszyn take 1 563 429 ms, and burn 60 ml a km.
const TO_KRUSZYN_MS = 1_563_429;
const TO_KRUSZYN_FUEL_ML = 2_280;

/** A driver with a run (45 l in the tank, at T0), the experience of a level and, unless told otherwise, a garage in every city. */
function driverOfLevel(int $level, array $run = [], bool $garages = true): array
{
    [$driver, $race] = driverWithRun(['seed' => str_repeat('0', 32), ...$run]);

    (new Ledger)->ensureWallet($driver->id);
    (new Ledger)->grantXp($driver->id, app(Rules::class)->playerXpToReach($level));

    if ($garages) {
        foreach (array_keys(app(Rules::class)->regions()) as $region) {
            CityGarage::query()->firstOrCreate(['user_id' => $driver->id, 'region' => $region], ['capacity' => 3]);
        }
    }

    return [$driver, $race];
}

function getMap(User $driver): TestResponse
{
    return test()->getJson('/api/v1/map', bearerFor($driver));
}

function travelTo(User $driver, string $to, array $headers = []): TestResponse
{
    return test()->postJson('/api/v1/travel', ['to' => $to], [...bearerFor($driver), ...$headers]);
}

describe('the map', function () {
    it('requires a token', function () {
        $this->getJson('/api/v1/map')->assertUnauthorized();
        $this->postJson('/api/v1/travel', ['to' => 'kruszyn'])->assertUnauthorized();
    });

    it('shows the cities, the roads, where the car is and what the other cities ask and cost', function () {
        [$driver] = driverOfLevel(1);

        $response = getMap($driver)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.here', 'zlomowo')
            ->assertJsonPath('data.level', 1)
            ->assertJsonPath('data.travel', null)
            ->assertJsonCount(5, 'data.cities')
            ->assertJsonCount(4, 'data.roads');

        $cities = collect($response->json('data.cities'))->keyBy('slug');

        expect($cities['zlomowo'])->toMatchArray(['name' => 'Złomowo', 'x' => 78, 'y' => 290, 'home' => true, 'here' => true, 'open' => true, 'level_required' => 1, 'route' => null, 'fit_percent' => 100])
            ->and($cities['kruszyn']['open'])->toBeFalse()
            ->and($cities['kruszyn']['level_required'])->toBe(3)
            ->and($cities['kruszyn']['route'])->toBe(['km' => 38, 'ms' => TO_KRUSZYN_MS, 'fuel_ml' => TO_KRUSZYN_FUEL_ML, 'boost' => ['ms' => 1_202_637, 'fuel_ml' => 3_192, 'police_chance_ppm' => 150_000]])
            ->and($cities['kruszyn']['track'])->toMatchArray(['slug' => 'kruszyn-quarry', 'surface' => 'gravel', 'lit' => false])
            ->and($cities['kruszyn']['track']['demand'])->toBe(['accel' => 5, 'grip' => 25, 'handling' => 25, 'braking' => 10, 'clearance' => 35])
            // A hatchback on street tires suits a gravel quarry badly.
            ->and($cities['kruszyn']['fit_percent'])->toBeBetween(40, 65)
            // The way to Suchy Pas goes through Kruszyn: 38 + 23 km.
            ->and($cities['suchy-pas']['route']['km'])->toBe(61)
            ->and($cities['autodrom']['level_required'])->toBe(12);
    });

    it('names the cities in the language of the driver', function () {
        [$driver] = driverOfLevel(1);

        $names = collect($this->getJson('/api/v1/map', [...bearerFor($driver), 'X-Locale' => 'pl'])->json('data.cities'))->pluck('name', 'slug');

        expect($names['wilcza-przelecz'])->toBe('Wilcza Przełęcz');
    });

    it('is the home city for a driver who has no car yet, with no routes and no fit', function () {
        $driver = User::factory()->guest()->create();

        $city = collect(getMap($driver)->assertOk()->json('data.cities'))->keyBy('slug')['kruszyn'];

        expect($city['route'])->toBeNull()->and($city['fit_percent'])->toBeNull();
    });
});

describe('leaving', function () {
    it('takes the car onto the road: it earns until it leaves, then stands with the fuel of the road out of its tank', function () {
        [$driver, $run] = driverOfLevel(3);
        clockAt(150_000);

        travelTo($driver, 'kruszyn')
            ->assertCreated()
            ->assertJsonPath('data.travel', ['from' => 'zlomowo', 'to' => 'kruszyn', 'distance_km' => 38, 'start_ms' => RUN_T0 + 150_000, 'arrive_ms' => RUN_T0 + 150_000 + TO_KRUSZYN_MS, 'speedup' => ['coins' => 6, 'remaining_ms' => TO_KRUSZYN_MS], 'boosted' => false, 'police' => null]);

        $run->refresh();

        // Two laps of 75 s, paid at $15.50 each, before it left.
        expect(cashOf($run))->toBe(3_100)
            ->and($run->laps_done)->toBe(2)
            ->and($run->status)->toBe(RaceRun::STATUS_STOPPED)
            ->and($run->stop_reason)->toBe(RaceRun::STOP_TRAVEL)
            ->and($run->fuel_ml)->toBe(45_000 - 2 * 1_500 - TO_KRUSZYN_FUEL_ML)
            ->and(Travel::query()->count())->toBe(1);
    });

    it('earns nothing on the road, and cannot be towed or refueled back onto the track', function () {
        [$driver, $run] = driverOfLevel(3);
        travelTo($driver, 'kruszyn')->assertCreated();
        (new Ledger)->post($driver->id, 100_000, 'gift', 'money:'.$driver->id);

        clockAt(1_000_000);

        getRun($driver)->assertOk()
            ->assertJsonPath('data.status', 'stopped')
            ->assertJsonPath('data.stop_reason', 'travel')
            ->assertJsonPath('data.laps_done', 0)
            ->assertJsonPath('data.current_lap', null)
            ->assertJsonPath('data.travel.to', 'kruszyn')
            ->assertJsonPath('data.travel.to_name', 'Kruszyn')
            ->assertJsonPath('data.travel.from_name', 'Złomowo')
            ->assertJsonPath('tow.available', false);

        test()->postJson("/api/v1/runs/{$run->id}/refuel", ['request_id' => 'r1'], bearerFor($driver))
            ->assertOk()
            ->assertJsonPath('data.status', 'stopped')
            ->assertJsonPath('data.stop_reason', 'travel');
    });

    it('shows the map with the journey on and no routes while the car is on the road', function () {
        [$driver] = driverOfLevel(3);
        travelTo($driver, 'kruszyn')->assertCreated();

        $cities = collect(getMap($driver)->assertOk()->assertJsonPath('data.travel.to', 'kruszyn')->json('data.cities'));

        expect($cities->pluck('route')->filter()->all())->toBe([]);
    });
});

describe('arriving', function () {
    it('brings the car to the city with the track of that city, driving from the moment it arrives, and pays nothing for the way', function () {
        [$driver, $run] = driverOfLevel(3);
        travelTo($driver, 'kruszyn')->assertCreated();

        clockAt(TO_KRUSZYN_MS + 5_000);

        getRun($driver)->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.stop_reason', null)
            ->assertJsonPath('data.track.slug', 'kruszyn-quarry')
            ->assertJsonPath('data.track.region', 'kruszyn')
            ->assertJsonPath('data.laps_done', 0)
            ->assertJsonPath('data.travel', null)
            ->assertJsonPath('data.cursor_ms', RUN_T0 + TO_KRUSZYN_MS);

        $run->refresh();

        expect($run->track_slug)->toBe('kruszyn-quarry')
            ->and(Travel::query()->first()->status)->toBe(Travel::STATUS_ARRIVED)
            ->and($run->config['lap_ms'])->toHaveKey('dry')
            // The track is not lit, and the lap that starts at night will be longer: the run was frozen for this track.
            ->and($run->config['night_lap_ppm'])->toBe(1_030_000)
            ->and(cashOf($run))->toBe(0);
    });

    it('is brought up to date by the map too, and the map then has the car in the new city', function () {
        [$driver] = driverOfLevel(3);
        travelTo($driver, 'kruszyn')->assertCreated();

        clockAt(TO_KRUSZYN_MS + 1_000);

        getMap($driver)->assertOk()
            ->assertJsonPath('data.here', 'kruszyn')
            ->assertJsonPath('data.travel', null)
            ->assertJsonPath('data.cities.0.route.km', 38);
    });

    it('lets the car go on from there, to a city that is two roads from where it started', function () {
        [$driver] = driverOfLevel(5);
        travelTo($driver, 'kruszyn')->assertCreated();
        clockAt(TO_KRUSZYN_MS + 1_000);

        travelTo($driver, 'suchy-pas')
            ->assertCreated()
            ->assertJsonPath('data.travel.from', 'kruszyn')
            ->assertJsonPath('data.travel.distance_km', 23);
    });
});

describe('what stops a journey', function () {
    it('refuses a city that the level of the driver does not open, and says what it takes and how to get it', function () {
        [$driver, $run] = driverOfLevel(2);

        travelTo($driver, 'kruszyn', ['X-Locale' => 'pl'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'city_locked')
            ->assertJsonPath('details', ['required' => 3, 'current' => 2])
            ->assertJsonPath('message', 'Wymaga poziomu gracza 3 (masz 2). Zdobywasz XP, jeżdżąc.');

        expect($run->fresh()->status)->toBe(RaceRun::STATUS_RUNNING)->and(Travel::query()->count())->toBe(0);
    });

    it('opens a city exactly at its level', function () {
        [$driver] = driverOfLevel(3);

        travelTo($driver, 'kruszyn')->assertCreated();
        expect(travelTo($driver, 'wilcza-przelecz')->status())->toBe(422);
    });

    it('refuses the city the car is in, a city that does not exist, and a second journey', function () {
        [$driver] = driverOfLevel(12);

        travelTo($driver, 'zlomowo')->assertStatus(422)->assertJsonPath('code', 'already_there');
        travelTo($driver, 'atlantis')->assertStatus(422)->assertJsonPath('code', 'city_not_found');

        travelTo($driver, 'kruszyn')->assertCreated();
        travelTo($driver, 'autodrom')->assertStatus(422)->assertJsonPath('code', 'already_travelling');
    });

    it('refuses a car that cannot go on, the one that stands for a breakdown and the one that stands in the workshop', function () {
        [$broken] = driverOfLevel(3, ['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => 'breakdown']);
        [$inWorkshop] = driverOfLevel(3, ['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => RaceRun::STOP_WORKSHOP]);

        travelTo($broken, 'kruszyn')->assertStatus(422)->assertJsonPath('code', 'car_cannot_travel');
        travelTo($inWorkshop, 'kruszyn')->assertStatus(422)->assertJsonPath('code', 'car_cannot_travel');
    });

    it('refuses a car whose fuel does not reach, and says how much it takes', function () {
        [$driver, $run] = driverOfLevel(3, ['fuel_ml' => 1_000]);

        travelTo($driver, 'kruszyn', ['X-Locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'travel_no_fuel')
            ->assertJsonPath('details', ['required' => '2.3', 'available' => '1.0'])
            ->assertJsonPath('message', 'Not enough fuel for the road: it takes 2.3 l and the tank has 1.0 l.');

        expect($run->fresh()->fuel_ml)->toBe(1_000);
    });

    it('needs a garage in the city where the car goes, and says to buy one there', function () {
        [$driver, $run] = driverOfLevel(3, garages: false);

        travelTo($driver, 'kruszyn', ['X-Locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_garage')
            ->assertJsonPath('message', 'You have no garage in Kruszyn. Buy one there first.');

        expect($run->fresh()->status)->toBe(RaceRun::STATUS_RUNNING)->and(Travel::query()->count())->toBe(0);
    });

    it('needs room in the garage there, and keeps the place for a car that is on its way', function () {
        [$driver, $run] = driverOfLevel(3);
        CityGarage::query()->where('user_id', $driver->id)->where('region', 'kruszyn')->update(['capacity' => 1]);

        // Another car of the driver is on the road to Kruszyn already, and has the only place of the garage.
        $other = RaceRun::factory()->create(['user_id' => $driver->id, 'status' => RaceRun::STATUS_STOPPED, 'stop_reason' => RaceRun::STOP_TRAVEL]);
        Travel::factory()->create(['user_id' => $driver->id, 'race_run_id' => $other->id, 'from_region' => 'zlomowo', 'to_region' => 'kruszyn']);

        travelTo($driver, 'kruszyn', ['X-Locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'garage_full')
            ->assertJsonPath('message', 'The garage in Kruszyn is full.');
    });

    it('does not take the car to another city by changing its track', function () {
        [$driver, $run] = driverOfLevel(3);

        test()->patchJson("/api/v1/runs/{$run->id}", ['track' => 'kruszyn-quarry'], bearerFor($driver))
            ->assertStatus(422)
            ->assertJsonPath('code', 'track_elsewhere');

        expect($run->fresh()->track_slug)->toBe('zlomowo-street');
    });

    it('does not let the workshop work on a car that is on the road', function () {
        [$driver] = driverOfLevel(3);
        $part = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires']);
        travelTo($driver, 'kruszyn')->assertCreated();

        test()->postJson('/api/v1/garage/jobs', ['kind' => 'mount', 'part' => $part->id], bearerFor($driver))
            ->assertStatus(422)
            ->assertJsonPath('code', 'car_travelling');
    });
});
