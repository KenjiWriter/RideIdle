<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\CityGarage;
use App\Models\LedgerEntry;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\Travel;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

// Finishing a timer at once for premium coins: the job the mechanic is at, and the journey of a car. The price is a coin for every started five minutes that were left.

beforeEach(fn () => withoutTireFee());
afterEach(fn () => Carbon::setTestNow());

const SPEEDUP_STEP_MS = 10_447;

/** A driver with a car at level 3 that has tires and brakes of tier 2 on it, cash for upgrades and the coins that are given. @return array{0: User, 1: RaceRun, 2: Part, 3: Part} */
function speedupDriver(int $coins = 10, int $cash = 1_000_000): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);
    $tires = Part::factory()->state(['part_slug' => 'street-tires-t2', 'slot' => 'tires'])->mountedOn($run->car)->create();
    $brakes = Part::factory()->state(['part_slug' => 'disc-brakes-t2', 'slot' => 'brakes'])->mountedOn($run->car)->create();
    (new Ledger)->post($driver->id, $cash, 'gift', "speedup-cash:{$driver->id}");

    if ($coins > 0) {
        (new Ledger)->postPremium($driver->id, $coins, 'gift', "speedup-coins:{$driver->id}");
    }

    return [$driver, $run, $tires, $brakes];
}

function speedupUpgrade(User $driver, Part $part): TestResponse
{
    return test()->postJson('/api/v1/garage/jobs', ['kind' => 'upgrade', 'part' => $part->id], bearerFor($driver));
}

function speedupFinish(User $driver, int $job, string $request = 'r-1'): TestResponse
{
    return test()->postJson("/api/v1/garage/jobs/{$job}/finish", ['request_id' => $request], bearerFor($driver));
}

/** A driver of level 3 with a garage in every city whose car is on the road to Kruszyn, and the coins that are given. @return array{0: User, 1: RaceRun} */
function speedupTraveller(int $coins = 10): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);
    (new Ledger)->ensureWallet($driver->id);
    (new Ledger)->grantXp($driver->id, app(Rules::class)->playerXpToReach(3));

    foreach (array_keys(app(Rules::class)->regions()) as $region) {
        CityGarage::query()->firstOrCreate(['user_id' => $driver->id, 'region' => $region], ['capacity' => 3]);
    }

    if ($coins > 0) {
        (new Ledger)->postPremium($driver->id, $coins, 'gift', "speedup-coins:{$driver->id}");
    }

    test()->postJson('/api/v1/travel', ['to' => 'kruszyn'], bearerFor($driver))->assertCreated();

    return [$driver, $run];
}

describe('the price', function () {
    it('is a coin for every started five minutes that were left, and never less than one', function () {
        $rules = app(Rules::class);

        expect(array_map(fn (int $ms): int => $rules->speedupCoins($ms), [0, 1, 299_999, 300_000, 300_001, 600_000, 1_563_429, 3_600_000]))->toBe([1, 1, 1, 1, 2, 2, 6, 12])
            ->and($rules->speedupMinutesPerCoin())->toBe(5);
    });

    it('is not asked when the rules sell nothing', function () {
        $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($data['premium']);

        expect(Rules::fromArray($data)->speedupCoins(60_000))->toBeNull();
    });
});

describe('finishing the job that the mechanic is at', function () {
    it('costs the coins of what was left, does the job at once and gives the car back to the track', function () {
        [$driver, $run, $tires] = speedupDriver();
        $job = speedupUpgrade($driver, $tires)->assertCreated()
            ->assertJsonPath('data.workshop.jobs.0.speedup', ['coins' => 1, 'remaining_ms' => SPEEDUP_STEP_MS])
            ->json('data.workshop.jobs.0.id');

        clockAt(2_000);

        speedupFinish($driver, $job)->assertOk()
            ->assertJsonPath('repeated', false)
            ->assertJsonPath('finished', $job)
            ->assertJsonPath('spent_coins', 1)
            ->assertJsonPath('premium_coins', 9)
            ->assertJsonPath('data.workshop.jobs', [])
            ->assertJsonPath('data.car.run.stop_reason', null)
            ->assertJsonPath('data.car.slots.0.part.upgrade', 1);

        $entry = LedgerEntry::query()->where('user_id', $driver->id)->where('reason', 'speedup')->sole();

        expect($tires->refresh()->upgrade_level)->toBe(1)
            ->and($entry->currency)->toBe('premium')
            ->and($entry->delta)->toBe(-1)
            ->and((new Ledger)->premiumBalance($driver->id))->toBe(9)
            ->and(WorkshopJob::query()->where('status', WorkshopJob::STATUS_DONE)->count())->toBe(1);
    });

    it('is told twice and charges once', function () {
        [$driver, $run, $tires] = speedupDriver();
        $job = speedupUpgrade($driver, $tires)->json('data.workshop.jobs.0.id');

        speedupFinish($driver, $job, 'same')->assertOk()->assertJsonPath('repeated', false);
        speedupFinish($driver, $job, 'same')->assertOk()->assertJsonPath('repeated', true)->assertJsonPath('spent_coins', 0)->assertJsonPath('premium_coins', 9);

        expect(LedgerEntry::query()->where('reason', 'speedup')->count())->toBe(1);
    });

    it('moves the jobs that wait up: the next one starts at once, with its own time', function () {
        [$driver, $run, $tires, $brakes] = speedupDriver();
        $first = speedupUpgrade($driver, $tires)->json('data.workshop.jobs.0.id');
        $queued = speedupUpgrade($driver, $brakes)->assertCreated();

        expect($queued->json('data.workshop.jobs.1.status'))->toBe('queued')
            ->and($queued->json('data.workshop.jobs.1.speedup'))->toBeNull();

        clockAt(1_000);

        $response = speedupFinish($driver, $first)->assertOk();
        $next = $response->json('data.workshop.jobs.0');

        expect($response->json('data.workshop.jobs'))->toHaveCount(1)
            ->and($next['status'])->toBe('working')
            ->and($next['start_ms'])->toBe(RUN_T0 + 1_000)
            ->and($next['finish_ms'])->toBe(RUN_T0 + 1_000 + SPEEDUP_STEP_MS)
            ->and($next['speedup']['coins'])->toBe(1)
            // The car does not drive between the two: it was in the workshop and it is in the workshop.
            ->and($response->json('data.car.run.stop_reason'))->toBe('workshop');
    });

    it('is refused for a job that waits, and nothing is charged', function () {
        [$driver, $run, $tires, $brakes] = speedupDriver();
        speedupUpgrade($driver, $tires);
        $waiting = speedupUpgrade($driver, $brakes)->json('data.workshop.jobs.1.id');

        speedupFinish($driver, $waiting)->assertStatus(422)->assertJsonPath('code', 'job_not_started');

        expect((new Ledger)->premiumBalance($driver->id))->toBe(10)
            ->and(LedgerEntry::query()->where('reason', 'speedup')->count())->toBe(0);
    });

    it('is refused when the driver has too few coins, and the job goes on as it was', function () {
        [$driver, $run, $tires] = speedupDriver(coins: 0);
        $job = speedupUpgrade($driver, $tires)->json('data.workshop.jobs.0.id');

        speedupFinish($driver, $job)->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_premium')
            ->assertJsonPath('details', ['available' => 0, 'required' => 1]);

        expect(WorkshopJob::query()->findOrFail($job)->status)->toBe(WorkshopJob::STATUS_WORKING)
            ->and($tires->refresh()->upgrade_level)->toBe(0);
    });

    it('is refused for a job that is done, that is not there or that is another driver\'s', function () {
        [$driver, $run, $tires] = speedupDriver();
        $job = speedupUpgrade($driver, $tires)->json('data.workshop.jobs.0.id');
        [$other] = speedupDriver();

        speedupFinish($other, $job)->assertStatus(422)->assertJsonPath('code', 'job_not_found');
        speedupFinish($driver, 9_999)->assertStatus(422)->assertJsonPath('code', 'job_not_found');

        // The job ended in the meantime: it is done, and it is not paid for.
        clockAt(SPEEDUP_END_MS);
        speedupFinish($driver, $job)->assertStatus(422)->assertJsonPath('code', 'job_done');

        expect((new Ledger)->premiumBalance($driver->id))->toBe(10);
    });

    it('says in the language of the driver what is missing', function () {
        [$driver, $run, $tires] = speedupDriver(coins: 0);
        $job = speedupUpgrade($driver, $tires)->json('data.workshop.jobs.0.id');

        test()->postJson("/api/v1/garage/jobs/{$job}/finish", ['request_id' => 'r-1'], [...bearerFor($driver), 'X-Locale' => 'pl'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Za mało monet premium: masz 0, potrzebujesz 1.');
    });

    it('asks a request id', function () {
        [$driver] = speedupDriver();

        test()->postJson('/api/v1/garage/jobs/1/finish', ['request_id' => 'r-1'])->assertUnauthorized();
        test()->postJson('/api/v1/garage/jobs/1/finish', [], bearerFor($driver))->assertStatus(422);
    });

    it('is not sold when the rules have no catalog', function () {
        [$driver, $run, $tires] = speedupDriver();
        $job = speedupUpgrade($driver, $tires)->assertJsonPath('data.workshop.jobs.0.speedup', ['coins' => 1, 'remaining_ms' => SPEEDUP_STEP_MS])->json('data.workshop.jobs.0.id');
        $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($data['premium']);
        app()->singleton(Rules::class, fn () => Rules::fromArray($data));

        speedupFinish($driver, $job)->assertStatus(422)->assertJsonPath('code', 'speedup_unavailable');
        test()->getJson('/api/v1/garage', bearerFor($driver))->assertOk()->assertJsonPath('data.workshop.jobs.0.speedup', null);
    });
});

describe('finishing a journey', function () {
    it('shows what it costs, brings the car to the city at once and takes the coins', function () {
        [$driver, $run] = speedupTraveller();
        clockAt(60_000);

        $map = test()->getJson('/api/v1/map', bearerFor($driver))->assertOk()->assertJsonPath('premium_coins', 10);

        expect($map->json('data.travel.speedup.coins'))->toBe(6)
            ->and($map->json('data.travel.speedup.remaining_ms'))->toBe($map->json('data.travel.arrive_ms') - RUN_T0 - 60_000);

        test()->postJson('/api/v1/travel/finish', ['request_id' => 'r-1'], bearerFor($driver))->assertOk()
            ->assertJsonPath('repeated', false)
            ->assertJsonPath('spent_coins', 6)
            ->assertJsonPath('premium_coins', 4)
            ->assertJsonPath('data.travel', null)
            ->assertJsonPath('data.here', 'kruszyn');

        $run->refresh();

        expect($run->track_slug)->toBe('kruszyn-quarry')
            ->and($run->stop_reason)->toBeNull()
            ->and(Travel::query()->sole()->status)->toBe(Travel::STATUS_ARRIVED)
            ->and(Travel::query()->sole()->arrive_ms)->toBe(RUN_T0 + 60_000);
    });

    it('is told twice and charges once', function () {
        [$driver] = speedupTraveller();

        test()->postJson('/api/v1/travel/finish', ['request_id' => 'same'], bearerFor($driver))->assertOk()->assertJsonPath('repeated', false);
        test()->postJson('/api/v1/travel/finish', ['request_id' => 'same'], bearerFor($driver))->assertOk()->assertJsonPath('repeated', true)->assertJsonPath('premium_coins', 4);
    });

    it('is refused when the car is not on the road, and when the coins are too few', function () {
        [$driver] = speedupTraveller();
        [$home] = speedupDriver();

        test()->postJson('/api/v1/travel/finish', ['request_id' => 'r-1'], bearerFor($home))->assertStatus(422)->assertJsonPath('code', 'not_travelling');

        [$poor] = speedupTraveller(coins: 5);

        test()->postJson('/api/v1/travel/finish', ['request_id' => 'r-1'], bearerFor($poor))->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_premium')
            ->assertJsonPath('details', ['available' => 5, 'required' => 6]);

        expect(Travel::query()->where('user_id', $poor->id)->sole()->status)->toBe(Travel::STATUS_TRAVELLING);
    });

    it('is asked for the car of the driver that was named, and refuses one that is not theirs', function () {
        [$driver, $run] = speedupTraveller();
        [$other] = speedupDriver();

        test()->postJson('/api/v1/travel/finish', ['request_id' => 'r-1', 'car' => $run->car_id + 1_000], bearerFor($driver))->assertStatus(422)->assertJsonPath('code', 'car_not_found');
        test()->postJson('/api/v1/travel/finish', ['request_id' => 'r-1', 'car' => $run->car_id], bearerFor($other))->assertStatus(422)->assertJsonPath('code', 'car_not_found');
        test()->postJson('/api/v1/travel/finish', ['request_id' => 'r-1', 'car' => $run->car_id], bearerFor($driver))->assertOk();
    });
});

/** The end of the first upgrade of the tires of tier 2, in ms after T0. */
const SPEEDUP_END_MS = SPEEDUP_STEP_MS + 1_000;
