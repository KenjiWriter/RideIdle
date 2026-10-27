<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\RaceRun;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Testing\TestResponse;

function startRun(User $driver, array $body = ['car' => 'rdzak-gt-4', 'track' => 'zlomowo-street'], array $headers = []): TestResponse
{
    return test()->postJson('/api/v1/runs', $body, [...bearerFor($driver), ...$headers]);
}

it('requires a token', function () {
    $this->postJson('/api/v1/runs', ['car' => 'rdzak-gt-4', 'track' => 'zlomowo-street'])->assertUnauthorized();
});

it('starts the run of a driver with the small tank that the tutorial arranges for the first run', function () {
    $driver = User::factory()->guest()->create();

    startRun($driver)
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.car.slug', 'rdzak-gt-4')
        ->assertJsonPath('data.car.name', 'Rdzak GT-4')
        ->assertJsonPath('data.track.slug', 'zlomowo-street')
        // The car waits in the garage until the driver sends it out (the Start of the tournament screen).
        ->assertJsonPath('data.status', 'stopped')
        ->assertJsonPath('data.stop_reason', 'parked')
        ->assertJsonPath('data.laps_done', 0)
        // 17 l in a tank of 45: it is low in four laps, which is where the tutorial teaches the tank.
        ->assertJsonPath('data.fuel_ml', 17_000)
        ->assertJsonPath('data.tank_ml', 45_000)
        ->assertJsonPath('data.state_version', 0)
        ->assertJsonPath('data.lap.ms.dry', 75_000)
        ->assertJsonPath('data.lap.cash_cents.rain', 1_442)
        ->assertJsonPath('data.lap.fuel_ml', 1_500)
        ->assertJsonPath('data.prices', ['fuel_cents_per_liter' => 200, 'repair_cents' => 3_000])
        ->assertJsonPath('wallet.cash_cents', 0)
        ->assertJsonPath('wallet.xp', 0)
        ->assertJsonStructure(['data' => ['id', 'cursor_ms', 'server_time_ms']]);

    $run = RaceRun::query()->where('user_id', $driver->id)->firstOrFail();

    expect($run->seed)->toMatch('/^[a-f0-9]{32}$/')
        ->and($run->cursor_ms)->toBeGreaterThan(1_700_000_000_000)
        ->and($run->config['lap_ms']['dry'])->toBe(75_000)
        // The first car breaks down after six laps, so that the tutorial can teach the repair.
        ->and($run->break_after_laps)->toBe(6);
});

it('starts every starter wreck with its own tank and lap', function (string $car, int $tankMl, int $dryLapMs, int $fuelPerLapMl) {
    $driver = User::factory()->guest()->create();

    startRun($driver, ['car' => $car, 'track' => 'zlomowo-street'])
        ->assertCreated()
        // The tank of the first run starts at 17 l, whichever wreck it is, and is as big as the wreck's.
        ->assertJsonPath('data.fuel_ml', 17_000)
        ->assertJsonPath('data.tank_ml', $tankMl)
        ->assertJsonPath('data.lap.ms.dry', $dryLapMs)
        ->assertJsonPath('data.lap.fuel_ml', $fuelPerLapMl);
})->with([
    'rdzak' => ['rdzak-gt-4', 45_000, 75_000, 1_500],
    'bizon' => ['bizon-m-2', 60_000, 73_394, 2_109],
    'kuna' => ['kuna-4x4', 70_000, 91_848, 1_500],
]);

it('names the car and the track in the language of the request', function (string $locale, string $track) {
    $driver = User::factory()->guest()->create();

    startRun($driver, headers: ['X-Locale' => $locale])
        ->assertCreated()
        ->assertHeader('Content-Language', $locale)
        ->assertJsonPath('data.track.name', $track);
})->with([
    'polish' => ['pl', 'Sprint uliczny Złomowo'],
    'english' => ['en', 'Złomowo Street Sprint'],
    'german' => ['de', 'Stadtsprint Złomowo'],
    'brazilian portuguese' => ['pt-BR', 'Sprint urbano de Złomowo'],
]);

it('refuses a car or a track that does not exist or is missing', function (array $body, array $invalid) {
    $driver = User::factory()->guest()->create();

    startRun($driver, $body)->assertUnprocessable()->assertJsonValidationErrors($invalid);

    expect(RaceRun::query()->count())->toBe(0);
})->with([
    'unknown car' => [['car' => 'no-such-car', 'track' => 'zlomowo-street'], ['car']],
    'unknown track' => [['car' => 'rdzak-gt-4', 'track' => 'no-such-track'], ['track']],
    'no car' => [['track' => 'zlomowo-street'], ['car']],
    'no track' => [['car' => 'rdzak-gt-4'], ['track']],
    'nothing' => [[], ['car', 'track']],
]);

it('refuses a second run of the same driver and says which one it is', function () {
    $driver = User::factory()->guest()->create();
    $first = startRun($driver)->assertCreated()->json('data.id');

    startRun($driver, ['car' => 'bizon-m-2', 'track' => 'zlomowo-street'], ['X-Locale' => 'pl'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'run_exists')
        ->assertJsonPath('run_id', $first)
        ->assertJsonPath('message', 'Masz już auto na torze.');

    expect(RaceRun::query()->count())->toBe(1);
});

it('lets two drivers start their own runs', function () {
    $a = User::factory()->guest()->create();
    $b = User::factory()->guest()->create();

    $first = startRun($a)->assertCreated()->json('data.id');
    $second = startRun($b)->assertCreated()->json('data.id');

    expect($first)->not->toBe($second)
        ->and(RaceRun::query()->count())->toBe(2);
});

describe('the level of the driver and the tracks', function () {
    it('refuses a track that the level of the driver does not open, and says what it takes and how to get it', function () {
        $driver = User::factory()->guest()->create();

        startRun($driver, ['car' => 'rdzak-gt-4', 'track' => 'autodrom-circuit'], ['X-Locale' => 'pl'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'track_locked')
            ->assertJsonPath('message', 'Wymaga poziomu gracza 12 (masz 1). Zdobywasz XP, jeżdżąc.');

        expect(RaceRun::query()->count())->toBe(0);
    });

    it('opens a track from the level that it asks for, and not one level before', function () {
        $rules = app(Rules::class);
        $before = User::factory()->guest()->create();
        $enough = User::factory()->guest()->create();

        // Kruszyn is open from level 3, which takes xp_base * 3 ^ xp_exponent experience.
        (new Ledger)->ensureWallet($before->id);
        (new Ledger)->grantXp($before->id, $rules->playerXpToReach(3) - 1);
        (new Ledger)->ensureWallet($enough->id);
        (new Ledger)->grantXp($enough->id, $rules->playerXpToReach(3));

        startRun($before, ['car' => 'kuna-4x4', 'track' => 'kruszyn-quarry'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'track_locked')
            ->assertJsonPath('details', ['required' => 3, 'current' => 2]);

        startRun($enough, ['car' => 'kuna-4x4', 'track' => 'kruszyn-quarry'])
            ->assertCreated()
            ->assertJsonPath('data.track.slug', 'kruszyn-quarry')
            ->assertJsonPath('data.lap.fuel_ml', 1_900);
    });
});

describe('sending the car out', function () {
    it('requires a token', function () {
        $this->postJson('/api/v1/runs/1/start')->assertUnauthorized();
    });

    it('sends out the car that waits in the garage: it drives from now on, and earned nothing while it stood', function () {
        clockAt(0);
        $driver = User::factory()->guest()->create();
        $run = RaceRun::query()->findOrFail(startRun($driver)->json('data.id'));

        // An hour passes with the car in the garage: nothing is earned, and nothing is owed.
        clockAt(3_600_000);
        getRun($driver)->assertOk()->assertJsonPath('data.status', 'stopped')->assertJsonPath('data.stop_reason', 'parked')->assertJsonPath('data.laps_done', 0)->assertJsonPath('wallet.cash_cents', 0);

        $response = test()->postJson("/api/v1/runs/{$run->id}/start", [], bearerFor($driver))->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.stop_reason', null)
            ->assertJsonPath('data.laps_done', 0);

        // It leaves with a head start of a minute, so its first lap (75 s) has 15 s to go.
        expect($response->json('data.cursor_ms'))->toBe(RUN_T0 + 3_600_000 - 60_000);

        clockAt(3_614_000);
        getRun($driver)->assertOk()->assertJsonPath('data.laps_done', 0);

        clockAt(3_615_000);
        getRun($driver)->assertOk()->assertJsonPath('data.laps_done', 1)->assertJsonPath('wallet.cash_cents', 1_550);

        // The second lap is a whole one again.
        clockAt(3_689_000);
        getRun($driver)->assertOk()->assertJsonPath('data.laps_done', 1);
        clockAt(3_690_000);
        getRun($driver)->assertOk()->assertJsonPath('data.laps_done', 2);
    });

    it('is harmless when pressed twice, and for a car that drives already', function () {
        $driver = User::factory()->guest()->create();
        $run = RaceRun::query()->findOrFail(startRun($driver)->json('data.id'));

        test()->postJson("/api/v1/runs/{$run->id}/start", [], bearerFor($driver))->assertOk();
        $version = $run->refresh()->state_version;

        clockAt(30_000);
        test()->postJson("/api/v1/runs/{$run->id}/start", [], bearerFor($driver))->assertOk()->assertJsonPath('data.status', 'running');

        expect($run->refresh()->state_version)->toBe($version);
    });

    it('does not send out a car that stands for another reason', function () {
        [$driver, $run] = driverWithRun(['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => 'breakdown']);

        test()->postJson("/api/v1/runs/{$run->id}/start", [], bearerFor($driver))->assertOk()->assertJsonPath('data.status', 'stopped')->assertJsonPath('data.stop_reason', 'breakdown');
    });

    it('does not start a run of another driver', function () {
        [, $run] = driverWithRun(['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => RaceRun::STOP_PARKED]);
        $other = User::factory()->guest()->create();

        test()->postJson("/api/v1/runs/{$run->id}/start", [], bearerFor($other))->assertNotFound()->assertJsonPath('code', 'no_run');
        expect($run->refresh()->stop_reason)->toBe(RaceRun::STOP_PARKED);
    });

    it('does not let fuel or the tow send out a car that waits: only the Start does', function () {
        $driver = User::factory()->guest()->create();
        $run = RaceRun::query()->findOrFail(startRun($driver)->json('data.id'));
        (new Ledger)->post($driver->id, 10_000, 'gift', 'money');

        // The first tank is small and the driver tops it up (28 l at $2): a tow is not offered to a car that has not left the garage.
        test()->postJson("/api/v1/runs/{$run->id}/refuel", ['request_id' => 'r1'], bearerFor($driver))->assertOk();
        test()->postJson("/api/v1/runs/{$run->id}/tow", [], bearerFor($driver))->assertOk()->assertJsonPath('towed', false);

        expect($run->refresh()->stop_reason)->toBe(RaceRun::STOP_PARKED);
    });
});
