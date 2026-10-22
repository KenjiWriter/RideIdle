<?php

declare(strict_types=1);

use App\Domain\Run\CarStats;
use App\Domain\Run\RunInputs;
use App\Domain\Run\TrackProfile;
use App\Domain\Run\WeatherEffect;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\SettlementEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger;
use App\Services\RunService;
use Database\Factories\RaceRunFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function runService(): RunService
{
    return new RunService(new Ledger());
}

/** @param  array<string, mixed>  $attributes */
function aRun(array $attributes = []): RaceRun
{
    return RaceRun::factory()->create($attributes);
}

function cashOf(RaceRun $run): int
{
    return (new Ledger())->balance($run->user_id);
}

it('starts a run with a frozen configuration and a new seed', function () {
    $user = User::factory()->guest()->create();
    $inputs = new RunInputs(
        car: new CarStats(['grip' => 100.0, 'power' => 100.0]),
        track: new TrackProfile(['grip' => 0.5, 'power' => 0.5], ['grip' => 100.0, 'power' => 100.0], 75_000, 1_500, 1_550, 25),
        weatherChancesPpm: ['dry' => 700_000, 'rain' => 300_000],
        weatherEffects: ['rain' => new WeatherEffect(['grip' => 0.85], 930_000, 930_000)],
        climateSeed: 'region-zlomowo',
        breakChancePerLapPpm: 10_000,
    );

    $run = runService()->start($user->id, 'rdzak-gt-4', 'zlomowo-street', $inputs, 45_000, 1_000_000);
    $other = runService()->start($user->id, 'rdzak-gt-4', 'zlomowo-street', $inputs, 45_000, 1_000_000);

    $fresh = RaceRun::query()->findOrFail($run->id);

    expect($fresh->cursor_ms)->toBe(1_000_000)
        ->and($fresh->fuel_ml)->toBe(45_000)
        ->and($fresh->laps_done)->toBe(0)
        ->and($fresh->state_version)->toBe(0)
        ->and($fresh->status)->toBe('running')
        ->and($fresh->config['lap_ms'])->toBe(['dry' => 75_000, 'rain' => 82_035])
        ->and($fresh->config['lap_cash_cents'])->toBe(['dry' => 1_550, 'rain' => 1_442])
        ->and($fresh->break_after_laps)->toBeInt()->toBeGreaterThan(0)
        ->and($fresh->seed)->toMatch('/^[a-f0-9]{32}$/')
        ->and($other->seed)->not->toBe($fresh->seed)
        ->and($fresh->ruleset_version)->toBe('dev')
        ->and(Wallet::query()->count())->toBe(0);
});

it('uses the application clock for the start of a run', function () {
    Carbon::setTestNow(Carbon::createFromTimestampMs(1_792_692_000_123));

    try {
        expect(RunService::nowMs())->toBe(1_792_692_000_123);
    } finally {
        Carbon::setTestNow();
    }
});

it('W1 in the database: credits the money, writes the settlement and its log, and updates the run', function () {
    $run = aRun(['fuel_ml' => 45_000]);

    $settled = runService()->settle($run->id, 3_600_000);

    $fresh = $run->fresh();

    expect($settled->changedAnything())->toBeTrue()
        ->and($settled->result->laps)->toBe(30)
        ->and($fresh->cursor_ms)->toBe(2_250_000)
        ->and($fresh->laps_done)->toBe(30)
        ->and($fresh->fuel_ml)->toBe(0)
        ->and($fresh->state_version)->toBe(1)
        ->and($fresh->status)->toBe('stopped')
        ->and($fresh->stop_reason)->toBe('fuel_empty')
        ->and(cashOf($run))->toBe(46_500)
        ->and(Wallet::query()->findOrFail($run->user_id)->xp)->toBe(750);

    $settlement = Settlement::query()->firstOrFail();

    expect($settlement->race_run_id)->toBe($run->id)
        ->and($settlement->state_version)->toBe(0)
        ->and($settlement->cursor_from_ms)->toBe(0)
        ->and($settlement->cursor_to_ms)->toBe(2_250_000)
        ->and($settlement->now_ms)->toBe(3_600_000)
        ->and($settlement->laps)->toBe(30)
        ->and($settlement->cash_cents)->toBe(46_500)
        ->and($settlement->xp)->toBe(750)
        ->and($settlement->stop_reason)->toBe('fuel_empty')
        ->and($settlement->events->pluck('type')->all())->toBe(['lap_batch', 'lap_batch', 'fuel_empty'])
        ->and($settlement->events->pluck('seq')->all())->toBe([1, 2, 3])
        ->and($settlement->events[0]->payload)->toMatchArray(['laps' => 18, 'cash_cents' => 27_900, 'weather' => 'dry']);

    $entry = LedgerEntry::query()->firstOrFail();

    expect($entry->idem_key)->toBe("settle:{$run->id}:0")
        ->and($entry->settlement_id)->toBe($settlement->id)
        ->and($entry->delta)->toBe(46_500)
        ->and($entry->reason)->toBe('settlement');
});

it('uses the application clock when no time is given', function () {
    $start = 1_792_692_000_000;
    $run = aRun(['fuel_ml' => 45_000, 'cursor_ms' => $start]);

    // A date after the rows were created: MySQL timestamps do not reach back to the epoch.
    Carbon::setTestNow(Carbon::createFromTimestampMs($start + 3_600_000));

    try {
        runService()->settle($run->id);
    } finally {
        Carbon::setTestNow();
    }

    expect(cashOf($run))->toBe(46_500)
        ->and($run->fresh()->cursor_ms)->toBe($start + 2_250_000);
});

it('changes nothing the second time at the same moment', function () {
    $run = aRun(['fuel_ml' => 45_000]);
    runService()->settle($run->id, 3_600_000);

    $again = runService()->settle($run->id, 3_600_000);

    expect($again->changedAnything())->toBeFalse()
        ->and($again->result->isEmpty())->toBeTrue()
        ->and($run->fresh()->state_version)->toBe(1)
        ->and(Settlement::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(1)
        ->and(cashOf($run))->toBe(46_500);
});

it('writes nothing when no lap was finished', function () {
    $run = aRun(['fuel_ml' => 45_000]);

    $settled = runService()->settle($run->id, 74_999);

    expect($settled->changedAnything())->toBeFalse()
        ->and(Settlement::query()->count())->toBe(0)
        ->and(Wallet::query()->count())->toBe(1) // the wallet was created under the lock
        ->and($run->fresh()->state_version)->toBe(0);
});

it('W2 in the database: two steps give the same money as one', function () {
    $stepped = aRun(['fuel_ml' => 60_000]);
    $once = aRun(['fuel_ml' => 60_000]);

    runService()->settle($stepped->id, 2_310_000);
    runService()->settle($stepped->id, 2_325_000);
    runService()->settle($once->id, 2_325_000);

    expect(cashOf($stepped))->toBe(48_050)
        ->and(cashOf($once))->toBe(48_050)
        ->and($stepped->fresh()->only(['cursor_ms', 'laps_done', 'fuel_ml']))->toBe($once->fresh()->only(['cursor_ms', 'laps_done', 'fuel_ml']))
        ->and($stepped->settlements()->orderBy('state_version')->pluck('state_version')->all())->toBe([0, 1])
        ->and(LedgerEntry::query()->where('user_id', $stepped->user_id)->orderBy('id')->pluck('idem_key')->all())
        ->toBe(["settle:{$stepped->id}:0", "settle:{$stepped->id}:1"]);
});

it('stops a run after the planned breakdown and logs it', function () {
    $run = aRun(['fuel_ml' => 450_000, 'break_after_laps' => 10]);

    runService()->settle($run->id, 3_600_000);

    $settlement = Settlement::query()->firstOrFail();

    expect($run->fresh()->status)->toBe('stopped')
        ->and($run->fresh()->stop_reason)->toBe('breakdown')
        ->and($run->fresh()->laps_done)->toBe(10)
        ->and(cashOf($run))->toBe(15_500)
        ->and($settlement->stop_reason)->toBe('breakdown')
        ->and($settlement->events->pluck('type')->all())->toBe(['lap_batch', 'breakdown']);
});

it('records a settlement and the XP even when a lap pays no money', function () {
    $run = aRun(['config' => RaceRunFactory::constantConfig(cash: 0), 'fuel_ml' => 45_000]);

    runService()->settle($run->id, 750_000);

    expect(Settlement::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and(Wallet::query()->findOrFail($run->user_id)->xp)->toBe(250);
});

it('writes nothing at all when something fails halfway', function () {
    $run = aRun(['fuel_ml' => 45_000]);

    // The settlement and its log are already written when the money is posted, so a failure there must undo them.
    $event = 'eloquent.creating: '.LedgerEntry::class;
    Event::listen($event, static function (): never {
        throw new RuntimeException('The ledger is down.');
    });

    try {
        expect(fn () => runService()->settle($run->id, 3_600_000))->toThrow(RuntimeException::class, 'The ledger is down.');
    } finally {
        Event::forget($event);
    }

    $fresh = $run->fresh();

    expect(Settlement::query()->count())->toBe(0)
        ->and(SettlementEvent::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($fresh->cursor_ms)->toBe(0)
        ->and($fresh->laps_done)->toBe(0)
        ->and($fresh->state_version)->toBe(0)
        ->and(Wallet::query()->find($run->user_id)?->xp ?? 0)->toBe(0);
});

it('cannot record the same settlement twice', function () {
    $run = aRun(['fuel_ml' => 45_000]);
    Settlement::query()->create([
        'race_run_id' => $run->id, 'state_version' => 0, 'cursor_from_ms' => 0, 'cursor_to_ms' => 0,
        'now_ms' => 0, 'laps' => 0, 'cash_cents' => 0, 'xp' => 0,
    ]);

    // A settlement of version 0 already exists, so a second one is refused and no money is paid.
    expect(fn () => runService()->settle($run->id, 3_600_000))->toThrow(QueryException::class);

    expect(LedgerEntry::query()->count())->toBe(0)
        ->and($run->fresh()->state_version)->toBe(0)
        ->and(cashOf($run))->toBe(0);
});

it('does not pay twice when the posting of a settlement is retried with the same key', function () {
    $run = aRun(['fuel_ml' => 45_000]);
    runService()->settle($run->id, 3_600_000);

    $entry = LedgerEntry::query()->firstOrFail();
    $again = (new Ledger())->post($run->user_id, $entry->delta, 'settlement', $entry->idem_key, $entry->settlement_id);

    expect($again->is($entry))->toBeTrue()
        ->and(cashOf($run))->toBe(46_500);
});

it('keeps the ledger equal to the sum of the settlements', function () {
    $run = aRun(['fuel_ml' => 600_000]);

    foreach ([500_000, 1_900_000, 1_900_000, 7_000_000, 20_000_000] as $now) {
        runService()->settle($run->id, $now);
    }

    $cash = (int) Settlement::query()->where('race_run_id', $run->id)->sum('cash_cents');
    $laps = (int) Settlement::query()->where('race_run_id', $run->id)->sum('laps');

    expect(cashOf($run))->toBe($cash)
        ->and($laps)->toBe($run->fresh()->laps_done)
        ->and((new Ledger())->isConsistent($run->user_id))->toBeTrue();
});

it('settles a long absence in windows', function () {
    // 10 days: more than three 72 hour windows. Fuel for 6 000 laps of 75 s (125 hours of driving).
    $run = aRun(['fuel_ml' => 9_000_000]);
    $tenDays = 10 * 24 * 3_600_000;

    runService()->settle($run->id, $tenDays);

    $fresh = $run->fresh();

    // 6 000 laps use all the fuel after 450 000 s = 125 h, well inside 10 days.
    expect($fresh->laps_done)->toBe(6_000)
        ->and($fresh->fuel_ml)->toBe(0)
        ->and(cashOf($run))->toBe(6_000 * 1_550)
        ->and($fresh->status)->toBe('stopped')
        ->and(Settlement::query()->count())->toBe(1);
});

it('uses the weather of the region seed in the frozen configuration', function () {
    $config = RaceRunFactory::constantConfig();
    $config['lap_ms'] = ['dry' => 75_000, 'rain' => 90_000];
    $config['lap_cash_cents'] = ['dry' => 1_550, 'rain' => 1_442];
    $config['lap_xp'] = ['dry' => 25, 'rain' => 23];
    $config['weather'] = ['seed' => 'region-zlomowo', 'chances_ppm' => ['dry' => 500_000, 'rain' => 500_000]];
    $run = aRun(['config' => $config, 'fuel_ml' => 150_000]);

    runService()->settle($run->id, 36_000_000);

    $batches = SettlementEvent::query()->where('type', 'lap_batch')->get();
    $expected = $batches->sum(fn ($event) => $event->payload['laps'] * $config['lap_cash_cents'][$event->payload['weather']]);

    expect($batches->pluck('payload.weather')->unique()->sort()->values()->all())->toBe(['dry', 'rain'])
        ->and(cashOf($run))->toBe((int) $expected);
});

it('fails for a run that does not exist', function () {
    runService()->settle(999_999, 1_000);
})->throws(ModelNotFoundException::class);

it('keeps the runs of two drivers apart', function () {
    $a = aRun(['fuel_ml' => 45_000]);
    $b = aRun(['fuel_ml' => 6_000]);

    runService()->settle($a->id, 3_600_000);
    runService()->settle($b->id, 3_600_000);

    expect(cashOf($a))->toBe(46_500)
        ->and(cashOf($b))->toBe(6_200)
        ->and(DB::table('wallets')->count())->toBe(2);
});
