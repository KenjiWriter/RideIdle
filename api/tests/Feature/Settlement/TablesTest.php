<?php

declare(strict_types=1);

use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\SettlementEvent;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function aSettlement(RaceRun $run, int $version = 0): Settlement
{
    return Settlement::query()->create([
        'race_run_id' => $run->id,
        'state_version' => $version,
        'cursor_from_ms' => 0,
        'cursor_to_ms' => 2_250_000,
        'now_ms' => 3_600_000,
        'laps' => 30,
        'cash_cents' => 46_500,
        'xp' => 750,
        'stop_reason' => 'fuel_empty',
    ]);
}

it('has the tables and columns settlement needs', function () {
    expect(Schema::hasColumns('wallets', ['user_id', 'cash_cents', 'xp']))->toBeTrue()
        ->and(Schema::hasColumns('race_runs', ['user_id', 'car_slug', 'track_slug', 'status', 'stop_reason', 'cursor_ms', 'laps_done', 'fuel_ml', 'break_after_laps', 'seed', 'config', 'ruleset_version', 'state_version']))->toBeTrue()
        ->and(Schema::hasColumns('settlements', ['race_run_id', 'state_version', 'cursor_from_ms', 'cursor_to_ms', 'now_ms', 'laps', 'cash_cents', 'xp', 'stop_reason']))->toBeTrue()
        ->and(Schema::hasColumns('settlement_events', ['settlement_id', 'seq', 'type', 'at_ms', 'payload']))->toBeTrue()
        ->and(Schema::hasColumns('ledger_entries', ['user_id', 'currency', 'delta', 'balance_after', 'reason', 'settlement_id', 'idem_key']))->toBeTrue();
});

it('stores a run with its frozen configuration and reads integers back', function () {
    $run = RaceRun::factory()->create(['break_after_laps' => 30, 'cursor_ms' => 1_792_692_000_000]);

    $fresh = RaceRun::query()->findOrFail($run->id);

    expect($fresh->config['lap_ms'])->toBe(['dry' => 75_000])
        ->and($fresh->cursor_ms)->toBe(1_792_692_000_000)
        ->and($fresh->fuel_ml)->toBe(45_000)
        ->and($fresh->break_after_laps)->toBe(30)
        ->and($fresh->state_version)->toBe(0)
        ->and($fresh->status)->toBe('running')
        ->and($fresh->seed)->toHaveLength(32);
});

it('records a settlement once for each version of a run', function () {
    $run = RaceRun::factory()->create();
    aSettlement($run, 0);
    aSettlement($run, 1);

    expect(fn () => aSettlement($run, 1))->toThrow(QueryException::class);
    expect($run->settlements()->count())->toBe(2);
});

it('keeps the events of a settlement in order and refuses a repeated position', function () {
    $settlement = aSettlement(RaceRun::factory()->create());

    SettlementEvent::query()->create(['settlement_id' => $settlement->id, 'seq' => 2, 'type' => 'fuel_empty', 'at_ms' => 2_250_000, 'payload' => []]);
    SettlementEvent::query()->create(['settlement_id' => $settlement->id, 'seq' => 1, 'type' => 'lap_batch', 'at_ms' => 2_250_000, 'payload' => ['laps' => 30]]);

    expect($settlement->events->pluck('type')->all())->toBe(['lap_batch', 'fuel_empty'])
        ->and($settlement->events->first()->payload)->toBe(['laps' => 30]);

    expect(fn () => SettlementEvent::query()->create(['settlement_id' => $settlement->id, 'seq' => 1, 'type' => 'x', 'at_ms' => 0, 'payload' => []]))
        ->toThrow(QueryException::class);
});

it('accepts a ledger key only once', function () {
    $user = User::factory()->guest()->create();
    Wallet::query()->create(['user_id' => $user->id]);

    $entry = fn (string $key) => LedgerEntry::query()->create([
        'user_id' => $user->id,
        'delta' => 100,
        'balance_after' => 100,
        'reason' => 'test',
        'idem_key' => $key,
    ]);

    $entry('settle:1:0');

    expect(fn () => $entry('settle:1:0'))->toThrow(QueryException::class);
    expect($entry('settle:1:1'))->toBeInstanceOf(LedgerEntry::class);
});

it('starts a wallet empty', function () {
    $user = User::factory()->guest()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id])->refresh();

    expect($wallet->cash_cents)->toBe(0)
        ->and($wallet->xp)->toBe(0)
        ->and($user->wallet->is($wallet))->toBeTrue();
});

it('never lets the balance go below zero', function () {
    $user = User::factory()->guest()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'cash_cents' => 100]);

    expect(fn () => $wallet->update(['cash_cents' => -1]))->toThrow(QueryException::class);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'SQLite cannot add the CHECK constraint after the table exists.');

it('removes runs, settlements, events, wallet and ledger together with a driver', function () {
    $user = User::factory()->guest()->create();
    Wallet::query()->create(['user_id' => $user->id, 'cash_cents' => 100]);
    $run = RaceRun::factory()->create(['user_id' => $user->id]);
    $settlement = aSettlement($run);
    SettlementEvent::query()->create(['settlement_id' => $settlement->id, 'seq' => 1, 'type' => 'lap_batch', 'at_ms' => 1, 'payload' => []]);
    LedgerEntry::query()->create(['user_id' => $user->id, 'delta' => 100, 'balance_after' => 100, 'reason' => 'settlement', 'settlement_id' => $settlement->id, 'idem_key' => 'k1']);

    $user->delete();

    expect(Wallet::query()->count())->toBe(0)
        ->and(RaceRun::query()->count())->toBe(0)
        ->and(Settlement::query()->count())->toBe(0)
        ->and(SettlementEvent::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('keeps the ledger entry when only the run is deleted', function () {
    $user = User::factory()->guest()->create();
    $run = RaceRun::factory()->create(['user_id' => $user->id]);
    $settlement = aSettlement($run);
    $entry = LedgerEntry::query()->create(['user_id' => $user->id, 'delta' => 100, 'balance_after' => 100, 'reason' => 'settlement', 'settlement_id' => $settlement->id, 'idem_key' => 'k1']);

    $run->delete();

    expect(Settlement::query()->count())->toBe(0)
        ->and($entry->refresh()->settlement_id)->toBeNull();
});

it('is cleaned up when an expired guest is pruned', function () {
    $user = User::factory()->guest()->create(['last_seen_at' => now()->subDays(100)]);
    RaceRun::factory()->create(['user_id' => $user->id]);

    $this->artisan('accounts:prune-guests')->assertSuccessful();

    expect(User::query()->count())->toBe(0)
        ->and(RaceRun::query()->count())->toBe(0);
});
