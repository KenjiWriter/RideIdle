<?php

declare(strict_types=1);

use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\User;
use Database\Factories\RaceRunFactory;
use Illuminate\Support\Carbon;

function towRun(User $driver, RaceRun $run): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/runs/{$run->id}/tow", [], bearerFor($driver));
}

afterEach(fn () => Carbon::setTestNow());

it('requires a token', function () {
    $this->postJson('/api/v1/runs/1/tow')->assertUnauthorized();
});

it('tows a car that broke down for a driver who cannot pay, and makes it wait', function () {
    // One lap earns $15.50 before the breakdown and the repair costs $30.00.
    [$driver, $run] = driverWithRun(['fuel_ml' => 45_000, 'break_after_laps' => 1]);
    clockAt(1_000_000);

    towRun($driver, $run)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('towed', true)
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.stop_reason', null)
        ->assertJsonPath('data.cursor_ms', RUN_T0 + 1_000_000 + 300_000)
        ->assertJsonPath('data.laps_done', 1)
        ->assertJsonPath('data.state_version', 2) // the settlement and the tow
        ->assertJsonPath('wallet.cash_cents', 1_550)
        ->assertJsonPath('tow.available', false)
        ->assertJsonPath('tow.delay_ms', 300_000);

    $fresh = $run->fresh();
    expect($fresh->seed)->not->toBe($run->seed)
        ->and($fresh->config['lap_ms']['dry'])->toBe(75_000)
        ->and(LedgerEntry::query()->where('reason', '!=', 'settlement')->count())->toBe(0); // the tow cost nothing
});

it('says before the tow that it is available, with the rule the tow itself uses', function () {
    [$driver, $run] = driverWithRun(['fuel_ml' => 45_000, 'break_after_laps' => 1]);
    clockAt(1_000_000);

    getRun($driver)
        ->assertOk()
        ->assertJsonPath('data.stop_reason', 'breakdown')
        ->assertJsonPath('tow', ['available' => true, 'delay_ms' => 300_000]);
});

it('does not offer the tow to a driver who can pay for the repair', function () {
    [$driver] = driverWithRun(['fuel_ml' => 45_000, 'break_after_laps' => 2]); // two laps earn $31.00
    clockAt(1_000_000);

    getRun($driver)->assertOk()->assertJsonPath('tow.available', false);
});

it('does not offer the tow to a car that is driving', function () {
    [$driver] = driverWithRun(['fuel_ml' => 45_000]);
    clockAt(1_000_000);

    getRun($driver)->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('tow.available', false);
});

it('leaves a driver who can pay for the repair as they are', function () {
    [$driver, $run] = driverWithRun(['fuel_ml' => 45_000, 'break_after_laps' => 2]);
    clockAt(1_000_000);

    towRun($driver, $run)
        ->assertOk()
        ->assertJsonPath('towed', false)
        ->assertJsonPath('data.status', 'stopped')
        ->assertJsonPath('data.stop_reason', 'breakdown')
        ->assertJsonPath('tow.available', false);

    expect($run->fresh()->seed)->toBe($run->seed);
});

it('leaves a car that is driving as it is', function () {
    [$driver, $run] = driverWithRun(['fuel_ml' => 45_000]);
    clockAt(1_000_000);

    towRun($driver, $run)->assertOk()->assertJsonPath('towed', false)->assertJsonPath('data.status', 'running');
});

it('tows a car that ran dry and leaves it fuel for two laps', function () {
    // Each lap pays one dollar and a lap of fuel costs more than the driver has by the time the tank is empty.
    [$driver, $run] = driverWithRun(['config' => RaceRunFactory::constantConfig(cash: 100), 'fuel_ml' => 1_500]);
    clockAt(1_000_000);

    getRun($driver)->assertJsonPath('data.stop_reason', 'fuel_empty')->assertJsonPath('wallet.cash_cents', 100)->assertJsonPath('tow.available', true);

    towRun($driver, $run)
        ->assertOk()
        ->assertJsonPath('towed', true)
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.fuel_ml', 3_000)
        ->assertJsonPath('wallet.cash_cents', 100);
});

it('tows once when it is asked for twice', function () {
    [$driver, $run] = driverWithRun(['fuel_ml' => 45_000, 'break_after_laps' => 1]);
    clockAt(1_000_000);

    towRun($driver, $run)->assertOk()->assertJsonPath('towed', true);
    $seed = $run->fresh()->seed;

    towRun($driver, $run)->assertOk()->assertJsonPath('towed', false)->assertJsonPath('data.state_version', 2);

    expect($run->fresh()->seed)->toBe($seed);
});

it('shows a towed car as running with the start in the future, so the client can count down', function () {
    [$driver, $run] = driverWithRun(['fuel_ml' => 45_000, 'break_after_laps' => 1]);
    clockAt(1_000_000);
    towRun($driver, $run)->assertOk();

    $answer = getRun($driver)->assertOk();

    expect($answer->json('data.status'))->toBe('running')
        ->and($answer->json('data.cursor_ms'))->toBeGreaterThan($answer->json('data.server_time_ms'));
});

it('does not know the run of another driver', function () {
    [, $run] = driverWithRun(['fuel_ml' => 45_000, 'break_after_laps' => 1]);
    $other = User::factory()->guest()->create();
    clockAt(1_000_000);

    towRun($other, $run)->assertNotFound()->assertJsonPath('code', 'no_run');

    expect($run->fresh()->state_version)->toBe(0);
});

it('does not know a run that does not exist', function () {
    $driver = User::factory()->guest()->create();

    test()->postJson('/api/v1/runs/999999/tow', [], bearerFor($driver))->assertNotFound();
});
