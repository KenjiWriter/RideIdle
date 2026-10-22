<?php

declare(strict_types=1);

use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Carbon;

/** @param  array<string, mixed>  $body */
function refuelRun(User $driver, RaceRun $run, array $body = ['request_id' => 'r1'], array $headers = []): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/runs/{$run->id}/refuel", $body, [...bearerFor($driver), ...$headers]);
}

/** A driver with some money and a run that starts with the given fuel. The price of fuel is $2 per liter. */
function richDriverWithRun(int $fuelMl, int $cents = 100_000, array $attributes = []): array
{
    [$driver, $run] = driverWithRun(['fuel_ml' => $fuelMl, ...$attributes]);

    if ($cents > 0) {
        (new Ledger())->post($driver->id, $cents, 'gift', 'seed-money:'.$driver->id);
    }

    return [$driver, $run];
}

afterEach(fn () => Carbon::setTestNow());

it('requires a token', function () {
    $this->postJson('/api/v1/runs/1/refuel', ['request_id' => 'r1'])->assertUnauthorized();
});

it('fills the tank and charges the wallet', function () {
    [$driver, $run] = richDriverWithRun(15_000);

    refuelRun($driver, $run)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('refuel', ['added_ml' => 30_000, 'cost_cents' => 6_000, 'repeated' => false])
        ->assertJsonPath('data.fuel_ml', 45_000)
        ->assertJsonPath('data.tank_ml', 45_000)
        ->assertJsonPath('data.state_version', 1)
        ->assertJsonPath('wallet.cash_cents', 94_000);

    $entry = LedgerEntry::query()->where('reason', 'refuel')->firstOrFail();

    expect($entry->delta)->toBe(-6_000)
        ->and($entry->idem_key)->toBe("refuel:{$run->id}:r1");
});

it('buys the liters asked for', function () {
    [$driver, $run] = richDriverWithRun(15_000);

    refuelRun($driver, $run, ['request_id' => 'r1', 'liters' => 10])
        ->assertOk()
        ->assertJsonPath('refuel.added_ml', 10_000)
        ->assertJsonPath('refuel.cost_cents', 2_000)
        ->assertJsonPath('data.fuel_ml', 25_000);
});

it('buys no more than the tank holds', function () {
    [$driver, $run] = richDriverWithRun(40_000);

    refuelRun($driver, $run, ['request_id' => 'r1', 'liters' => 20])
        ->assertOk()
        ->assertJsonPath('refuel.added_ml', 5_000)
        ->assertJsonPath('refuel.cost_cents', 1_000)
        ->assertJsonPath('data.fuel_ml', 45_000);
});

it('charges nothing for a full tank', function () {
    [$driver, $run] = richDriverWithRun(45_000);

    refuelRun($driver, $run)
        ->assertOk()
        ->assertJsonPath('refuel', ['added_ml' => 0, 'cost_cents' => 0, 'repeated' => false])
        ->assertJsonPath('wallet.cash_cents', 100_000);

    expect(LedgerEntry::query()->where('reason', 'refuel')->count())->toBe(0);
});

it('charges and fills once when the same request arrives again', function () {
    [$driver, $run] = richDriverWithRun(15_000);

    refuelRun($driver, $run, ['request_id' => 'r1', 'liters' => 10])->assertOk()->assertJsonPath('refuel.repeated', false);

    refuelRun($driver, $run, ['request_id' => 'r1', 'liters' => 10])
        ->assertOk()
        ->assertJsonPath('refuel', ['added_ml' => 0, 'cost_cents' => 0, 'repeated' => true])
        ->assertJsonPath('data.fuel_ml', 25_000)
        ->assertJsonPath('wallet.cash_cents', 98_000);

    expect(LedgerEntry::query()->where('reason', 'refuel')->count())->toBe(1);
});

it('settles first, so what the car earned pays for the fuel and a stopped car drives again from now', function () {
    // Four laps of fuel and no money: the car earns $62.00 and runs dry at 300 s, the driver comes back at 600 s.
    [$driver, $run] = richDriverWithRun(6_000, cents: 0);
    clockAt(600_000);

    refuelRun($driver, $run, ['request_id' => 'r1', 'liters' => 10])
        ->assertOk()
        ->assertJsonPath('refuel.cost_cents', 2_000)
        ->assertJsonPath('wallet.cash_cents', 4 * 1_550 - 2_000)
        ->assertJsonPath('data.laps_done', 4)
        ->assertJsonPath('data.fuel_ml', 10_000)
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.stop_reason', null)
        ->assertJsonPath('data.cursor_ms', RUN_T0 + 600_000);

    // The settlement that paid for the fuel is not lost: it is there for a client that asks since its version.
    getRun($driver, 'current?since_version=0')->assertJsonPath('summary.laps', 4)->assertJsonPath('summary.stop_reason', 'fuel_empty');
});

it('does not start a car that broke down', function () {
    [$driver, $run] = richDriverWithRun(20_000, attributes: ['break_after_laps' => 2]);
    clockAt(600_000);

    refuelRun($driver, $run, ['request_id' => 'r1', 'liters' => 10])
        ->assertOk()
        ->assertJsonPath('data.status', 'stopped')
        ->assertJsonPath('data.stop_reason', 'breakdown')
        ->assertJsonPath('data.fuel_ml', 17_000 + 10_000);
});

it('changes nothing when the driver cannot pay, not even the settlement', function () {
    // $62.00 earned in four laps, a full tank of 45 l costs $90.00.
    [$driver, $run] = richDriverWithRun(6_000, cents: 0);
    clockAt(600_000);

    refuelRun($driver, $run, headers: ['X-Locale' => 'pl'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'insufficient_funds')
        ->assertJsonPath('message', 'Za mało gotówki.')
        ->assertJsonPath('available_cents', 4 * 1_550)
        ->assertJsonPath('required_cents', 9_000);

    $fresh = $run->fresh();

    expect($fresh->fuel_ml)->toBe(6_000)
        ->and($fresh->state_version)->toBe(0)
        ->and(Settlement::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('refuses an invalid request', function (array $body, array $invalid) {
    [$driver, $run] = richDriverWithRun(15_000);

    refuelRun($driver, $run, $body)->assertUnprocessable()->assertJsonValidationErrors($invalid);

    expect($run->fresh()->fuel_ml)->toBe(15_000);
})->with([
    'no request id' => [[], ['request_id']],
    'empty request id' => [['request_id' => ''], ['request_id']],
    'request id too long' => [['request_id' => str_repeat('x', 65)], ['request_id']],
    'no liters' => [['request_id' => 'r1', 'liters' => 0], ['liters']],
    'negative liters' => [['request_id' => 'r1', 'liters' => -5], ['liters']],
    'liters as text' => [['request_id' => 'r1', 'liters' => 'much'], ['liters']],
    'fractions of a liter' => [['request_id' => 'r1', 'liters' => 1.5], ['liters']],
    'absurd liters' => [['request_id' => 'r1', 'liters' => 5_000_000], ['liters']],
]);

it('does not know the run of another driver', function () {
    [, $run] = richDriverWithRun(15_000);
    $other = User::factory()->guest()->create();

    refuelRun($other, $run)->assertNotFound()->assertJsonPath('code', 'no_run');

    expect($run->fresh()->fuel_ml)->toBe(15_000);
});

it('does not know a run that does not exist', function () {
    $driver = User::factory()->guest()->create();

    test()->postJson('/api/v1/runs/999999/refuel', ['request_id' => 'r1'], bearerFor($driver))->assertNotFound();
});
