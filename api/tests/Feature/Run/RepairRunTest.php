<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Carbon;

/** @param  array<string, mixed>  $body */
function repairRun(User $driver, RaceRun $run, array $body = ['request_id' => 'r1'], array $headers = []): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/runs/{$run->id}/repair", $body, [...bearerFor($driver), ...$headers]);
}

/** A run that breaks down after its second lap (150 s), for a driver with the given money. A repair costs $30.00. */
function brokenDownRun(int $cents = 0, array $attributes = []): array
{
    [$driver, $run] = driverWithRun(['fuel_ml' => 45_000, 'break_after_laps' => 2, ...$attributes]);

    if ($cents > 0) {
        (new Ledger())->post($driver->id, $cents, 'gift', 'seed-money:'.$driver->id);
    }

    return [$driver, $run];
}

afterEach(fn () => Carbon::setTestNow());

it('requires a token', function () {
    $this->postJson('/api/v1/runs/1/repair', ['request_id' => 'r1'])->assertUnauthorized();
});

it('repairs a car that broke down: charged, a new plan, driving again from now', function () {
    [$driver, $run] = brokenDownRun(cents: 10_000);
    clockAt(1_000_000);

    repairRun($driver, $run)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('repair', ['repaired' => true, 'cost_cents' => 3_000, 'repeated' => false])
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.stop_reason', null)
        ->assertJsonPath('data.laps_done', 2)
        ->assertJsonPath('data.cursor_ms', RUN_T0 + 1_000_000)
        ->assertJsonPath('data.state_version', 2) // the settlement of the two laps and the repair
        ->assertJsonPath('wallet.cash_cents', 10_000 + 2 * 1_550 - 3_000);

    $fresh = $run->fresh();

    expect($fresh->seed)->not->toBe($run->seed)
        ->and($fresh->break_after_laps)->toBeInt()->toBeGreaterThan(2)
        ->and($fresh->config['lap_ms']['dry'])->toBe(75_000);

    $entry = LedgerEntry::query()->where('reason', 'repair')->firstOrFail();

    expect($entry->delta)->toBe(-3_000)
        ->and($entry->idem_key)->toBe("repair:{$run->id}:r1");
});

it('is paid with what the car earned before it broke down', function () {
    // The wallet is empty. Three laps earn $46.50 and a repair costs $30.00.
    [$driver, $run] = brokenDownRun(attributes: ['break_after_laps' => 3]);
    clockAt(1_000_000);

    repairRun($driver, $run)
        ->assertOk()
        ->assertJsonPath('repair.repaired', true)
        ->assertJsonPath('wallet.cash_cents', 3 * 1_550 - 3_000);
});

it('changes nothing when the driver cannot pay, not even the settlement', function () {
    // The wallet is empty and the one lap before the breakdown earns $15.50, but the repair costs $30.00.
    [$driver, $run] = brokenDownRun(attributes: ['break_after_laps' => 1]);
    $seed = $run->seed;
    clockAt(1_000_000);

    repairRun($driver, $run, headers: ['X-Locale' => 'pl'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'insufficient_funds')
        ->assertJsonPath('message', 'Za mało gotówki.')
        ->assertJsonPath('available_cents', 1_550)
        ->assertJsonPath('required_cents', 3_000);

    $fresh = $run->fresh();

    expect($fresh->seed)->toBe($seed)
        ->and($fresh->laps_done)->toBe(0)
        ->and($fresh->state_version)->toBe(0)
        ->and(Settlement::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('leaves a car that is driving as it is and charges nothing', function () {
    [$driver, $run] = driverWithRun(['fuel_ml' => 45_000, 'break_after_laps' => 40]);
    (new Ledger())->post($driver->id, 10_000, 'gift', 'seed-money');
    clockAt(1_000_000);

    repairRun($driver, $run)
        ->assertOk()
        ->assertJsonPath('repair', ['repaired' => false, 'cost_cents' => 0, 'repeated' => false])
        ->assertJsonPath('data.status', 'running');

    expect($run->fresh()->seed)->toBe($run->seed)
        ->and($run->fresh()->break_after_laps)->toBe(40)
        ->and(LedgerEntry::query()->where('reason', 'repair')->count())->toBe(0);
});

it('leaves a car that only ran out of fuel stopped', function () {
    [$driver, $run] = driverWithRun(['fuel_ml' => 3_000]);
    (new Ledger())->post($driver->id, 10_000, 'gift', 'seed-money');
    clockAt(1_000_000);

    repairRun($driver, $run)
        ->assertOk()
        ->assertJsonPath('repair.repaired', false)
        ->assertJsonPath('data.status', 'stopped')
        ->assertJsonPath('data.stop_reason', 'fuel_empty');
});

it('repairs and charges once when the same request arrives again', function () {
    [$driver, $run] = brokenDownRun(cents: 10_000);
    clockAt(1_000_000);

    repairRun($driver, $run)->assertOk()->assertJsonPath('repair.repaired', true);
    $seed = $run->fresh()->seed;

    repairRun($driver, $run)
        ->assertOk()
        ->assertJsonPath('repair', ['repaired' => false, 'cost_cents' => 0, 'repeated' => true])
        ->assertJsonPath('wallet.cash_cents', 10_000 + 2 * 1_550 - 3_000);

    expect($run->fresh()->seed)->toBe($seed)
        ->and(LedgerEntry::query()->where('reason', 'repair')->count())->toBe(1);
});

it('is free when the rules say so', function () {
    $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true);
    $data['repair']['cost_cents'] = 0;
    app()->instance(Rules::class, Rules::fromArray($data));

    [$driver, $run] = brokenDownRun();
    clockAt(1_000_000);

    repairRun($driver, $run)
        ->assertOk()
        ->assertJsonPath('repair', ['repaired' => true, 'cost_cents' => 0, 'repeated' => false])
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('wallet.cash_cents', 2 * 1_550);

    expect(LedgerEntry::query()->where('reason', 'repair')->count())->toBe(0);
});

it('refuses an invalid request', function (array $body) {
    [$driver, $run] = brokenDownRun(cents: 10_000);
    clockAt(1_000_000);

    repairRun($driver, $run, $body)->assertUnprocessable()->assertJsonValidationErrors(['request_id']);

    expect($run->fresh()->state_version)->toBe(0);
})->with([
    'no request id' => [[]],
    'empty request id' => [['request_id' => '']],
    'request id too long' => [['request_id' => str_repeat('x', 65)]],
]);

it('does not know the run of another driver', function () {
    [, $run] = brokenDownRun(cents: 10_000);
    $other = User::factory()->guest()->create();
    clockAt(1_000_000);

    repairRun($other, $run)->assertNotFound()->assertJsonPath('code', 'no_run');

    expect($run->fresh()->state_version)->toBe(0);
});

it('does not know a run that does not exist', function () {
    $driver = User::factory()->guest()->create();

    test()->postJson('/api/v1/runs/999999/repair', ['request_id' => 'r1'], bearerFor($driver))->assertNotFound();
});
