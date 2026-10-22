<?php

declare(strict_types=1);

use App\Exceptions\InsufficientFundsException;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger;
use Symfony\Component\Process\Process;

/*
| Real concurrency: separate PHP processes hit the same database at the same moment. SQLite cannot show
| this (it locks the whole file and ignores FOR UPDATE), so these tests run on MySQL/MariaDB and PostgreSQL
| only. The tests commit their rows, so they clean up after themselves.
*/

beforeEach(function () {
    $this->driverIds = [];

    if (config('database.default') === 'sqlite') {
        $this->markTestSkipped('Row locks and parallel writers need MySQL, MariaDB or PostgreSQL.');
    }

    $this->artisan('migrate', ['--force' => true]);
});

afterEach(function () {
    if ($this->driverIds !== []) {
        // The foreign keys cascade to wallets, runs, settlements and ledger entries.
        User::query()->whereIn('id', $this->driverIds)->delete();
    }
});

/**
 * Starts one worker process per list of operations, all with the same start time, and returns their results.
 *
 * @param  list<list<array<string, mixed>>>  $workers
 * @return list<list<array<string, mixed>>>
 */
function runWorkers(array $workers): array
{
    $connection = config('database.default');
    $database = config("database.connections.{$connection}");
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) $database['host'],
        'DB_PORT' => (string) $database['port'],
        'DB_DATABASE' => (string) $database['database'],
        'DB_USERNAME' => (string) $database['username'],
        'DB_PASSWORD' => (string) $database['password'],
        'DB_URL' => '',
    ];

    $startAt = microtime(true) + 3.0;
    $processes = [];

    foreach ($workers as $ops) {
        $job = json_encode(['start_at' => $startAt, 'ops' => $ops], JSON_THROW_ON_ERROR);
        $process = new Process([PHP_BINARY, __DIR__.'/worker.php', $job], base_path(), $env);
        $process->setTimeout(120);
        $process->start();
        $processes[] = $process;
    }

    $results = [];

    foreach ($processes as $process) {
        $process->wait();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("A worker failed:\n".$process->getErrorOutput().$process->getOutput());
        }

        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    return $results;
}

/** @param  list<list<array<string, mixed>>>  $results */
function flattenResults(array $results): Illuminate\Support\Collection
{
    return collect($results)->flatten(1);
}

/** Everything that went wrong other than a refused payment, for example a deadlock or a lock timeout. */
function unexpectedErrors(Illuminate\Support\Collection $operations): array
{
    return $operations->where('ok', false)->reject(fn (array $op) => $op['error'] === InsufficientFundsException::class)->values()->all();
}

function committedDriver(object $test): User
{
    $user = User::factory()->guest()->create();
    $test->driverIds[] = $user->id;

    return $user;
}

function committedRun(object $test, array $attributes = []): RaceRun
{
    $run = RaceRun::factory()->create($attributes);
    $test->driverIds[] = $run->user_id;

    return $run;
}

it('posts the same key from eight processes at once and changes the balance once', function () {
    $user = committedDriver($this);

    $op = ['type' => 'post', 'user' => $user->id, 'delta' => 1_000, 'reason' => 'settlement', 'key' => 'same-key'];
    $operations = flattenResults(runWorkers(array_fill(0, 8, [$op])));

    expect(unexpectedErrors($operations))->toBe([])
        ->and($operations->pluck('value.entry')->unique()->count())->toBe(1)
        ->and(LedgerEntry::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and((new Ledger())->balance($user->id))->toBe(1_000);
});

it('never lets parallel costs take the balance below zero', function () {
    $user = committedDriver($this);
    (new Ledger())->post($user->id, 500, 'gift', 'start');

    $workers = array_map(
        fn (int $i) => [['type' => 'post', 'user' => $user->id, 'delta' => -100, 'reason' => 'purchase', 'key' => "cost-{$i}"]],
        range(1, 8),
    );
    $operations = flattenResults(runWorkers($workers));

    expect(unexpectedErrors($operations))->toBe([])
        ->and($operations->where('ok', true)->count())->toBe(5)
        ->and($operations->where('ok', false)->count())->toBe(3)
        ->and((new Ledger())->balance($user->id))->toBe(0)
        ->and((new Ledger())->isConsistent($user->id))->toBeTrue();
});

it('settles the same run from eight processes at the same moment and pays once', function () {
    $run = committedRun($this, ['fuel_ml' => 45_000]);

    $op = ['type' => 'settle', 'run' => $run->id, 'now' => 3_600_000];
    $operations = flattenResults(runWorkers(array_fill(0, 8, [$op])));

    expect(unexpectedErrors($operations))->toBe([])
        ->and($operations->where('value.changed', true)->count())->toBe(1)
        ->and(Settlement::query()->where('race_run_id', $run->id)->count())->toBe(1)
        ->and(LedgerEntry::query()->where('user_id', $run->user_id)->count())->toBe(1)
        ->and(cashOf($run))->toBe(46_500)
        ->and($run->fresh()->state_version)->toBe(1);
});

it('charges and fills once when eight processes send the same refuel request', function () {
    $run = committedRun($this, ['fuel_ml' => 15_000]);
    (new Ledger())->post($run->user_id, 100_000, 'gift', "start:{$run->id}");

    $op = ['type' => 'refuel', 'run' => $run->id, 'request' => 'same-request', 'tank' => 45_000, 'price' => 150, 'add' => 10_000, 'now' => 0];
    $operations = flattenResults(runWorkers(array_fill(0, 8, [$op])));

    expect(unexpectedErrors($operations))->toBe([])
        ->and($operations->where('value.repeated', false)->count())->toBe(1)
        ->and($operations->where('value.repeated', true)->count())->toBe(7)
        ->and($run->fresh()->fuel_ml)->toBe(25_000)
        ->and(LedgerEntry::query()->where('reason', 'refuel')->where('user_id', $run->user_id)->count())->toBe(1)
        ->and(cashOf($run))->toBe(98_500);
});

it('repairs a broken run once when eight processes send the same paid repair', function () {
    $run = committedRun($this, ['fuel_ml' => 45_000, 'break_after_laps' => 2]);
    (new Ledger())->post($run->user_id, 10_000, 'gift', "start:{$run->id}");
    $seed = $run->seed;

    $op = ['type' => 'repair', 'run' => $run->id, 'request' => 'same-request', 'cost' => 2_500, 'chance' => 50_000, 'now' => 1_000_000];
    $operations = flattenResults(runWorkers(array_fill(0, 8, [$op])));

    $fresh = $run->fresh();

    expect(unexpectedErrors($operations))->toBe([])
        ->and($operations->where('value.repaired', true)->count())->toBe(1)
        ->and($operations->where('value.repeated', true)->count())->toBe(7)
        ->and($fresh->seed)->not->toBe($seed)
        ->and($fresh->status)->toBe('running')
        ->and($fresh->state_version)->toBe(2) // the settlement of the two laps and the repair
        ->and(LedgerEntry::query()->where('reason', 'repair')->where('user_id', $run->user_id)->count())->toBe(1)
        ->and(cashOf($run))->toBe(10_000 + 2 * 1_550 - 2_500);
});

it('repairs a broken run once when eight processes ask for the same free repair', function () {
    $run = committedRun($this, ['fuel_ml' => 45_000, 'break_after_laps' => 2]);
    $seed = $run->seed;

    // No request id: it is the state of the run that stops the others, the car is no longer broken.
    $op = ['type' => 'repair', 'run' => $run->id, 'request' => null, 'cost' => 0, 'chance' => 50_000, 'now' => 1_000_000];
    $operations = flattenResults(runWorkers(array_fill(0, 8, [$op])));

    $fresh = $run->fresh();

    expect(unexpectedErrors($operations))->toBe([])
        ->and($operations->where('value.repaired', true)->count())->toBe(1)
        ->and($fresh->seed)->not->toBe($seed)
        ->and($fresh->state_version)->toBe(2);
});

it('settles, refuels and repairs in parallel without deadlocks and keeps the books straight', function () {
    // The car breaks down after 3 laps and after that with a chance of 30% per lap, so repairs really happen.
    $run = committedRun($this, ['fuel_ml' => 6_000, 'break_after_laps' => 3]);
    (new Ledger())->post($run->user_id, 20_000, 'gift', "start:{$run->id}");

    $settlers = array_map(
        fn (int $worker) => array_map(
            fn (int $i) => ['type' => 'settle', 'run' => $run->id, 'now' => ($i * 3 + $worker) * 13_333],
            range(1, 15),
        ),
        range(0, 2),
    );
    $refuelers = array_map(
        fn (int $worker) => array_map(
            fn (int $i) => ['type' => 'refuel', 'run' => $run->id, 'request' => "w{$worker}-{$i}", 'tank' => 45_000, 'price' => 150, 'add' => 3_000, 'now' => ($i * 5 + $worker) * 20_000],
            range(1, 10),
        ),
        range(0, 2),
    );

    $repairers = array_map(
        fn (int $worker) => array_map(
            fn (int $i) => ['type' => 'repair', 'run' => $run->id, 'request' => "p{$worker}-{$i}", 'cost' => 100, 'chance' => 300_000, 'now' => ($i * 4 + $worker) * 25_000],
            range(1, 6),
        ),
        range(0, 1),
    );

    $operations = flattenResults(runWorkers([...$settlers, ...$refuelers, ...$repairers]));

    // A deadlock or a lock timeout shows up here as an error other than a refused payment.
    expect(unexpectedErrors($operations))->toBe([]);

    $settlements = Settlement::query()->where('race_run_id', $run->id)->get();
    $refuels = $operations->where('ok', true)->where('type', 'refuel')->pluck('value')->where('repeated', false);
    $repairs = $operations->where('ok', true)->where('type', 'repair')->pluck('value')->where('repaired', true);
    $fresh = $run->fresh();
    $ledger = new Ledger();

    expect($ledger->isConsistent($run->user_id))->toBeTrue()
        // every settlement was paid exactly once...
        ->and((int) LedgerEntry::query()->where('user_id', $run->user_id)->where('reason', 'settlement')->sum('delta'))->toBe((int) $settlements->sum('cash_cents'))
        // ...the laps of the run are the laps that were settled...
        ->and($fresh->laps_done)->toBe((int) $settlements->sum('laps'))
        // ...the money is what was given plus what was earned minus what the fuel and the repairs cost...
        ->and($ledger->balance($run->user_id))->toBe(20_000 + (int) $settlements->sum('cash_cents') - (int) $refuels->sum('cost') - (int) $repairs->sum('cost'))
        ->and((int) LedgerEntry::query()->where('user_id', $run->user_id)->where('reason', 'repair')->sum('delta'))->toBe(-(int) $repairs->sum('cost'))
        // ...the fuel is what was in the tank plus what was bought minus what was driven...
        ->and($fresh->fuel_ml)->toBe(6_000 + (int) $refuels->sum('added') - $fresh->laps_done * 1_500)
        ->and($fresh->fuel_ml)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(45_000)
        // ...every change of the state has its own version...
        ->and($fresh->state_version)->toBe($settlements->count() + $refuels->where('added', '>', 0)->count() + $repairs->count())
        // ...and the repair path was really walked: the car breaks down at its third lap whatever the order.
        ->and($repairs->count())->toBeGreaterThanOrEqual(1);
});
