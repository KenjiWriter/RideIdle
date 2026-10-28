<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\CityGarage;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Travel;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

// A boosted journey: the car drives 30% faster and burns 40% more, and the police may stop it on the way. Whether they do, and where, is drawn when the car leaves; the
// driver is told only when it happens, has a minute to choose (pay, bribe, lawyer), and when the time runs out the fine is paid for them.

afterEach(fn () => Carbon::setTestNow());

// The stock Rdzak takes 1 563 429 ms to Kruszyn and burns 2 280 ml; boosted: 1 202 637 ms and 3 192 ml.
const BOOSTED_MS = 1_202_637;
const BOOSTED_FUEL_ML = 3_192;
const BOOST_STOP_MS = 120_000;

/** The rules with the boost changed (`$boost` replaces keys of `travel.boost`), the premium catalog left out when `$premium` is false. */
function boostRules(array $boost = [], bool $premium = true): void
{
    $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);
    $data['travel']['boost'] = [...$data['travel']['boost'], ...$boost];

    if (! $premium) {
        unset($data['premium']);
    }

    app()->singleton(Rules::class, fn () => Rules::fromArray($data));
}

/** The police stop every boosted journey half way. */
function alwaysHalfWay(array $more = []): void
{
    boostRules(['police_chance_ppm' => 1_000_000, 'police_from_ppm' => 500_000, 'police_to_ppm' => 500_000, ...$more]);
}

/** A driver of level 3 with a garage in every city, the cash and the coins that are given, and the run of the stock Rdzak at T0. @return array{0: User, 1: RaceRun} */
function boostDriver(int $cash = 1_000_000, int $coins = 10, array $run = []): array
{
    [$driver, $race] = driverWithRun(['seed' => str_repeat('0', 32), ...$run]);
    (new Ledger)->ensureWallet($driver->id);
    (new Ledger)->grantXp($driver->id, app(Rules::class)->playerXpToReach(3));

    foreach (array_keys(app(Rules::class)->regions()) as $region) {
        CityGarage::query()->firstOrCreate(['user_id' => $driver->id, 'region' => $region], ['capacity' => 3]);
    }

    if ($cash > 0) {
        (new Ledger)->post($driver->id, $cash, 'gift', "boost-cash:{$driver->id}");
    }

    if ($coins > 0) {
        (new Ledger)->postPremium($driver->id, $coins, 'gift', "boost-coins:{$driver->id}");
    }

    return [$driver, $race];
}

function boostTo(User $driver, string $to = 'kruszyn', bool $boost = true): TestResponse
{
    return test()->postJson('/api/v1/travel', ['to' => $to, 'boost' => $boost], bearerFor($driver));
}

function boostMap(User $driver, array $headers = []): TestResponse
{
    return test()->getJson('/api/v1/map', [...bearerFor($driver), ...$headers]);
}

function boostChoose(User $driver, string $choice, array $headers = []): TestResponse
{
    return test()->postJson('/api/v1/travel/police', ['choice' => $choice], [...bearerFor($driver), ...$headers]);
}

describe('a boosted journey', function () {
    it('takes 23% less time and burns 40% more fuel, and says so before the car leaves', function () {
        [$driver, $run] = boostDriver();

        boostMap($driver)->assertOk()->assertJsonPath('data.cities.1.route.boost', ['ms' => BOOSTED_MS, 'fuel_ml' => BOOSTED_FUEL_ML, 'police_chance_ppm' => 150_000]);

        boostTo($driver)->assertCreated()
            ->assertJsonPath('data.travel.boosted', true)
            ->assertJsonPath('data.travel.arrive_ms', RUN_T0 + BOOSTED_MS)
            ->assertJsonPath('data.travel.police', null);

        expect($run->refresh()->fuel_ml)->toBe(45_000 - BOOSTED_FUEL_ML)
            ->and(Travel::query()->sole()->boosted)->toBeTrue();
    });

    it('is a journey as it was when it is not asked for: no police, the old time and fuel', function () {
        alwaysHalfWay();
        [$driver, $run] = boostDriver();

        boostTo($driver, boost: false)->assertCreated()
            ->assertJsonPath('data.travel.boosted', false)
            ->assertJsonPath('data.travel.arrive_ms', RUN_T0 + TO_BOOST_NORMAL_MS);

        expect($run->refresh()->fuel_ml)->toBe(45_000 - 2_280)
            ->and(Travel::query()->sole()->police_at_ms)->toBeNull();
    });

    it('is refused when the car cannot carry the fuel of it, though it could carry that of a normal journey', function () {
        [$driver, $run] = boostDriver(run: ['fuel_ml' => 3_000]);

        boostTo($driver)->assertStatus(422)->assertJsonPath('code', 'travel_no_fuel')->assertJsonPath('details.required', '3.2');

        boostTo($driver, boost: false)->assertCreated();
    });

    it('is not sold when the rules have no boost', function () {
        $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($data['travel']['boost']);
        app()->singleton(Rules::class, fn () => Rules::fromArray($data));
        [$driver] = boostDriver();

        boostMap($driver)->assertOk()->assertJsonPath('data.cities.1.route.boost', null);
        boostTo($driver)->assertStatus(422)->assertJsonPath('code', 'boost_unavailable');
        expect(Travel::query()->count())->toBe(0);
    });

    it('is never stopped when the chance is none', function () {
        boostRules(['police_chance_ppm' => 0]);
        [$driver] = boostDriver();

        boostTo($driver)->assertCreated();

        expect(Travel::query()->sole()->police_at_ms)->toBeNull();
    });
});

const TO_BOOST_NORMAL_MS = 1_563_429;

describe('the police', function () {
    it('are not known before they stop the car, and then say what each choice costs', function () {
        alwaysHalfWay();
        [$driver] = boostDriver();
        boostTo($driver)->assertCreated();

        clockAt(600_000);
        boostMap($driver)->assertOk()->assertJsonPath('data.travel.police', null);

        clockAt(601_318);
        $police = boostMap($driver)->assertOk()->json('data.travel.police');

        expect($police['status'])->toBe('pending')
            ->and($police['at_ms'])->toBe(RUN_T0 + 601_318)
            ->and($police['decide_by_ms'])->toBe(RUN_T0 + 601_318 + 60_000)
            ->and($police['fine_cents'])->toBeGreaterThanOrEqual(500)
            ->and($police['options']['pay'])->toBe(['cents' => $police['fine_cents'], 'stop_ms' => BOOST_STOP_MS])
            ->and($police['options']['bribe']['cents'])->toBe((int) round($police['fine_cents'] * 1.5))
            ->and($police['options']['bribe']['risk_ppm'])->toBe(250_000)
            ->and($police['options']['lawyer'])->toBe(['coins' => 5]);
    });

    it('take the fine when the driver pays, and the car stands a little before it goes on', function () {
        alwaysHalfWay();
        [$driver, $run] = boostDriver();
        boostTo($driver)->assertCreated();
        clockAt(601_318);
        $fine = boostMap($driver)->json('data.travel.police.fine_cents');
        $cash = (new Ledger)->balance($driver->id);

        boostChoose($driver, 'pay')->assertOk()
            ->assertJsonPath('repeated', false)
            ->assertJsonPath('police', ['choice' => 'pay', 'outcome' => 'paid', 'paid_cents' => $fine])
            ->assertJsonPath('data.travel.arrive_ms', RUN_T0 + BOOSTED_MS + BOOST_STOP_MS)
            ->assertJsonPath('data.travel.police.status', 'resolved')
            ->assertJsonPath('data.travel.police.options', null);

        expect((new Ledger)->balance($driver->id))->toBe($cash - $fine)
            ->and(LedgerEntry::query()->where('reason', 'police_fine')->value('delta'))->toBe(-$fine);

        // The car is not there when it would have been, and is there when it has stood.
        clockAt(BOOSTED_MS + 1_000);
        boostMap($driver)->assertOk()->assertJsonPath('data.here', 'zlomowo');
        clockAt(BOOSTED_MS + BOOST_STOP_MS + 1_000);
        boostMap($driver)->assertOk()->assertJsonPath('data.here', 'kruszyn')->assertJsonPath('data.travel', null);
    });

    it('are told the same choice twice and take the fine once, and do not take another choice after', function () {
        alwaysHalfWay();
        [$driver] = boostDriver();
        boostTo($driver)->assertCreated();
        clockAt(601_318);

        boostChoose($driver, 'pay')->assertOk()->assertJsonPath('repeated', false);
        $cash = (new Ledger)->balance($driver->id);
        boostChoose($driver, 'pay')->assertOk()->assertJsonPath('repeated', true);
        boostChoose($driver, 'lawyer')->assertStatus(422)->assertJsonPath('code', 'police_resolved');

        expect((new Ledger)->balance($driver->id))->toBe($cash)
            ->and(LedgerEntry::query()->where('reason', 'police_fine')->count())->toBe(1)
            ->and((new Ledger)->premiumBalance($driver->id))->toBe(10);
    });

    it('let the car go on at once when a bribe works, for a half more than the fine', function () {
        alwaysHalfWay(['bribe_fail_ppm' => 0]);
        [$driver] = boostDriver();
        boostTo($driver)->assertCreated();
        clockAt(601_318);
        $police = boostMap($driver)->json('data.travel.police');
        $cash = (new Ledger)->balance($driver->id);

        boostChoose($driver, 'bribe')->assertOk()
            ->assertJsonPath('police.outcome', 'bribed')
            ->assertJsonPath('police.paid_cents', $police['options']['bribe']['cents'])
            ->assertJsonPath('data.travel.arrive_ms', RUN_T0 + BOOSTED_MS);

        expect((new Ledger)->balance($driver->id))->toBe($cash - $police['options']['bribe']['cents'])
            ->and(LedgerEntry::query()->where('reason', 'police_bribe')->count())->toBe(1);
    });

    it('take the bribe and write the bigger fine when it does not work, and the car stands longer', function () {
        alwaysHalfWay(['bribe_fail_ppm' => 1_000_000]);
        [$driver] = boostDriver();
        boostTo($driver)->assertCreated();
        clockAt(601_318);
        $police = boostMap($driver)->json('data.travel.police');

        boostChoose($driver, 'bribe')->assertOk()
            ->assertJsonPath('police.outcome', 'bribe_failed')
            ->assertJsonPath('police.paid_cents', $police['options']['bribe']['fail_cents'])
            ->assertJsonPath('data.travel.arrive_ms', RUN_T0 + BOOSTED_MS + 300_000);
    });

    it('do not take a bribe the driver cannot pay, and nothing changes', function () {
        alwaysHalfWay();
        [$driver] = boostDriver(cash: 0);
        boostTo($driver)->assertCreated();
        clockAt(601_318);

        boostChoose($driver, 'bribe')->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');

        boostMap($driver)->assertJsonPath('data.travel.police.status', 'pending');
    });

    it('are sent away by a lawyer for the coins: no fine and the car does not stand', function () {
        alwaysHalfWay();
        [$driver] = boostDriver();
        boostTo($driver)->assertCreated();
        clockAt(601_318);
        $cash = (new Ledger)->balance($driver->id);

        boostChoose($driver, 'lawyer')->assertOk()
            ->assertJsonPath('police', ['choice' => 'lawyer', 'outcome' => 'lawyered', 'paid_cents' => 0])
            ->assertJsonPath('data.travel.arrive_ms', RUN_T0 + BOOSTED_MS);

        expect((new Ledger)->balance($driver->id))->toBe($cash)
            ->and((new Ledger)->premiumBalance($driver->id))->toBe(5)
            ->and(LedgerEntry::query()->where('reason', 'police_lawyer')->value('currency'))->toBe('premium');
    });

    it('are not sent away by a lawyer the driver cannot pay', function () {
        alwaysHalfWay();
        [$poor] = boostDriver(coins: 0);
        boostTo($poor)->assertCreated();
        clockAt(601_318);

        boostChoose($poor, 'lawyer')->assertStatus(422)->assertJsonPath('code', 'insufficient_premium');

        boostMap($poor)->assertJsonPath('data.travel.police.status', 'pending');
    });

    it('have no lawyer to hire when the game sells no coins', function () {
        boostRules(['police_chance_ppm' => 1_000_000, 'police_from_ppm' => 500_000, 'police_to_ppm' => 500_000], premium: false);
        [$driver] = boostDriver();
        boostTo($driver)->assertCreated();
        clockAt(601_318);

        boostMap($driver)->assertJsonPath('data.travel.police.options.lawyer', null);
        boostChoose($driver, 'lawyer')->assertStatus(422)->assertJsonPath('code', 'police_no_lawyer');
    });

    it('are paid for the driver when the minute runs out, and the driver finds it done', function () {
        alwaysHalfWay();
        [$driver] = boostDriver();
        boostTo($driver)->assertCreated();
        clockAt(601_318);
        $fine = boostMap($driver)->json('data.travel.police.fine_cents');
        $cash = (new Ledger)->balance($driver->id);

        clockAt(601_318 + 61_000);

        boostMap($driver)->assertOk()
            ->assertJsonPath('data.travel.police.status', 'resolved')
            ->assertJsonPath('data.travel.police.choice', 'default')
            ->assertJsonPath('data.travel.police.outcome', 'defaulted')
            ->assertJsonPath('data.travel.arrive_ms', RUN_T0 + BOOSTED_MS + BOOST_STOP_MS);

        expect((new Ledger)->balance($driver->id))->toBe($cash - $fine);

        boostChoose($driver, 'lawyer')->assertStatus(422)->assertJsonPath('code', 'police_resolved');
    });

    it('are paid for the driver who comes back after the car would have arrived, and the car arrives after the stop', function () {
        alwaysHalfWay();
        [$driver, $run] = boostDriver();
        boostTo($driver)->assertCreated();

        clockAt(BOOSTED_MS + 10_000);
        boostMap($driver)->assertOk()->assertJsonPath('data.here', 'zlomowo');

        clockAt(BOOSTED_MS + BOOST_STOP_MS + 1_000);
        boostMap($driver)->assertOk()->assertJsonPath('data.here', 'kruszyn');

        $journey = Travel::query()->sole();

        expect($journey->police_outcome)->toBe('defaulted')
            ->and($journey->arrive_ms)->toBe(RUN_T0 + BOOSTED_MS + BOOST_STOP_MS)
            ->and($journey->status)->toBe(Travel::STATUS_ARRIVED)
            ->and($run->refresh()->track_slug)->toBe('kruszyn-quarry');
    });

    it('take what the driver has and no more, and the car stands all the same', function () {
        alwaysHalfWay();
        [$driver] = boostDriver(cash: 100);
        boostTo($driver)->assertCreated();
        clockAt(601_318);

        boostChoose($driver, 'pay')->assertOk()
            ->assertJsonPath('police.paid_cents', 100)
            ->assertJsonPath('data.travel.arrive_ms', RUN_T0 + BOOSTED_MS + BOOST_STOP_MS);

        expect((new Ledger)->balance($driver->id))->toBe(0);
    });

    it('are not there when they have not stopped the car yet, or did not stop it, or the car is not on the road', function () {
        alwaysHalfWay();
        [$driver] = boostDriver();
        boostTo($driver)->assertCreated();

        boostChoose($driver, 'pay')->assertStatus(422)->assertJsonPath('code', 'no_police_stop');

        // A journey that was not boosted is never stopped, whatever the chance of the boosted ones.
        [$calm] = boostDriver();
        boostTo($calm, boost: false)->assertCreated();
        clockAt(900_000);

        boostChoose($calm, 'pay')->assertStatus(422)->assertJsonPath('code', 'no_police_stop');

        [$home] = boostDriver();

        boostChoose($home, 'pay')->assertStatus(422)->assertJsonPath('code', 'not_travelling');
    });

    it('do not stop a car that is brought to the city at once for coins, and the fine is not asked', function () {
        alwaysHalfWay();
        [$driver] = boostDriver();
        boostTo($driver)->assertCreated();
        clockAt(100_000);
        $cash = (new Ledger)->balance($driver->id);

        test()->postJson('/api/v1/travel/finish', ['request_id' => 'r-1'], bearerFor($driver))->assertOk()->assertJsonPath('data.here', 'kruszyn');

        expect((new Ledger)->balance($driver->id))->toBe($cash)
            ->and(Travel::query()->sole()->police_outcome)->toBe('waived');
    });

    it('ask a choice that is one of the three', function () {
        test()->postJson('/api/v1/travel/police', ['choice' => 'pay'])->assertUnauthorized();

        [$driver] = boostDriver();

        test()->postJson('/api/v1/travel/police', ['choice' => 'run'], bearerFor($driver))->assertStatus(422);
    });

    it('speak the language of the driver', function () {
        alwaysHalfWay();
        [$driver] = boostDriver();
        boostTo($driver)->assertCreated();
        clockAt(601_318);
        boostChoose($driver, 'pay')->assertOk();

        boostChoose($driver, 'lawyer', ['X-Locale' => 'pl'])->assertStatus(422)->assertJsonPath('message', 'Z policją już załatwione.');
    });
});
