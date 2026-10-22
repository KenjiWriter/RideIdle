<?php

declare(strict_types=1);

use App\Models\RaceRun;
use App\Models\User;
use Illuminate\Support\Carbon;

function getRun(User $driver, string $path = 'current', array $headers = []): Illuminate\Testing\TestResponse
{
    return test()->getJson("/api/v1/runs/{$path}", [...bearerFor($driver), ...$headers]);
}

afterEach(fn () => Carbon::setTestNow());

it('requires a token', function () {
    $this->getJson('/api/v1/runs/current')->assertUnauthorized();
    $this->getJson('/api/v1/runs/1')->assertUnauthorized();
});

it('says so when the driver has no run', function () {
    $driver = User::factory()->guest()->create();

    getRun($driver, headers: ['X-Locale' => 'pl'])
        ->assertNotFound()
        ->assertJsonPath('code', 'no_run')
        ->assertJsonPath('message', 'Nie masz jeszcze auta na torze.');
});

it('W1 over HTTP: settles the run up to now and tells what happened', function () {
    [$driver, $run] = driverWithRun();
    clockAt(3_600_000);

    getRun($driver)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.id', $run->id)
        ->assertJsonPath('data.status', 'stopped')
        ->assertJsonPath('data.stop_reason', 'fuel_empty')
        ->assertJsonPath('data.laps_done', 30)
        ->assertJsonPath('data.fuel_ml', 0)
        ->assertJsonPath('data.cursor_ms', RUN_T0 + 2_250_000)
        ->assertJsonPath('data.state_version', 1)
        ->assertJsonPath('data.server_time_ms', RUN_T0 + 3_600_000)
        ->assertJsonPath('wallet.cash_cents', 46_500)
        ->assertJsonPath('wallet.xp', 750)
        ->assertJsonCount(1, 'settlements')
        ->assertJsonPath('settlements.0.version', 0)
        ->assertJsonPath('settlements.0.laps', 30)
        ->assertJsonPath('settlements.0.cash_cents', 46_500)
        ->assertJsonPath('settlements.0.stop_reason', 'fuel_empty')
        ->assertJsonPath('settlements.0.from_ms', RUN_T0)
        ->assertJsonPath('settlements.0.to_ms', RUN_T0 + 2_250_000)
        ->assertJsonPath('settlements.0.events.0.type', 'lap_batch')
        ->assertJsonPath('settlements.0.events.0.payload.laps', 18)
        ->assertJsonPath('settlements.0.events.2.type', 'fuel_empty')
        ->assertJsonPath('summary', [
            'laps' => 30, 'cash_cents' => 46_500, 'xp' => 750,
            'from_ms' => RUN_T0, 'to_ms' => RUN_T0 + 2_250_000, 'stop_reason' => 'fuel_empty',
        ]);
});

it('gives events without details an empty object and not an empty list', function () {
    [$driver] = driverWithRun();
    clockAt(3_600_000);

    $json = getRun($driver)->assertOk()->getContent();

    expect($json)->toContain('"type":"fuel_empty"')->toContain('"payload":{}');
});

it('settles nothing the second time at the same moment', function () {
    [$driver] = driverWithRun();
    clockAt(3_600_000);
    getRun($driver)->assertOk();

    getRun($driver)
        ->assertOk()
        ->assertJsonPath('data.state_version', 1)
        ->assertJsonPath('wallet.cash_cents', 46_500)
        ->assertJsonCount(0, 'settlements')
        ->assertJsonPath('summary', ['laps' => 0, 'cash_cents' => 0, 'xp' => 0, 'from_ms' => null, 'to_ms' => null, 'stop_reason' => null]);
});

it('gives the settlements made since a version, so an answer that did not arrive is not lost', function () {
    [$driver] = driverWithRun(['fuel_ml' => 100_000]);

    clockAt(600_000);
    getRun($driver)->assertOk()->assertJsonPath('data.laps_done', 8)->assertJsonPath('data.state_version', 1);

    clockAt(1_000_000);
    getRun($driver)->assertOk()->assertJsonPath('data.laps_done', 13)->assertJsonCount(1, 'settlements')->assertJsonPath('summary.laps', 5);

    // The client saw version 1 last and asks again: both settlements since version 0 are still there.
    getRun($driver, 'current?since_version=0')
        ->assertOk()
        ->assertJsonCount(2, 'settlements')
        ->assertJsonPath('settlements.0.version', 0)
        ->assertJsonPath('settlements.1.version', 1)
        ->assertJsonPath('summary.laps', 13)
        ->assertJsonPath('summary.cash_cents', 13 * 1_550)
        ->assertJsonPath('summary.from_ms', RUN_T0)
        ->assertJsonPath('summary.to_ms', RUN_T0 + 13 * 75_000);

    getRun($driver, 'current?since_version=1')->assertOk()->assertJsonCount(1, 'settlements')->assertJsonPath('summary.laps', 5);
    getRun($driver, 'current?since_version=2')->assertOk()->assertJsonCount(0, 'settlements');
});

it('reports the breakdown as the reason of the last stop', function () {
    [$driver] = driverWithRun(['break_after_laps' => 10]);
    clockAt(3_600_000);

    getRun($driver)
        ->assertOk()
        ->assertJsonPath('data.stop_reason', 'breakdown')
        ->assertJsonPath('summary.stop_reason', 'breakdown')
        ->assertJsonPath('summary.laps', 10);
});

it('shows a run by its id', function () {
    [$driver, $run] = driverWithRun();
    clockAt(750_000);

    getRun($driver, (string) $run->id)->assertOk()->assertJsonPath('data.id', $run->id)->assertJsonPath('data.laps_done', 10);
});

it('does not show the run of another driver, and does not settle it', function () {
    [, $run] = driverWithRun();
    $other = User::factory()->guest()->create();
    clockAt(3_600_000);

    getRun($other, (string) $run->id)->assertNotFound()->assertJsonPath('code', 'no_run');

    expect($run->fresh()->state_version)->toBe(0);
});

it('does not know a run that does not exist or an id that is not a number', function () {
    [$driver] = driverWithRun();

    getRun($driver, '999999')->assertNotFound();
    getRun($driver, 'abc')->assertNotFound();
});

it('refuses a version that is not a number', function (string $value) {
    [$driver] = driverWithRun();

    getRun($driver, "current?since_version={$value}")->assertUnprocessable()->assertJsonValidationErrors(['since_version']);
})->with(['negative' => '-1', 'text' => 'abc', 'fraction' => '1.5']);

it('names the car and the track in the language of the request', function () {
    [$driver] = driverWithRun();

    getRun($driver, headers: ['X-Locale' => 'pl'])->assertOk()->assertJsonPath('data.track.name', 'Sprint uliczny Złomowo');
});
