<?php

declare(strict_types=1);

use App\Domain\Run\RunFreezer;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\User;
use Database\Factories\RaceRunFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;

it('settles with the old configuration, then freezes the new one and continues from now', function () {
    $run = aRun(['fuel_ml' => 45_000]);
    $inputs = dryInputs(stat: 130.0);

    $changed = runService()->reconfigure($run->id, $inputs, 1_000_000);

    $expected = (new RunFreezer())->freeze($inputs, $run->seed, 13)->config;

    // toEqual: MySQL JSON columns do not keep the order of the keys.
    expect($changed->config)->toEqual($expected)
        ->and(lapMsOf($changed))->toBeLessThan(75_000)
        ->and($changed->laps_done)->toBe(13)
        ->and($changed->fuel_ml)->toBe(45_000 - 13 * 1_500)
        ->and($changed->cursor_ms)->toBe(1_000_000)
        ->and($changed->status)->toBe('running')
        ->and($changed->state_version)->toBe(2) // one settlement and one reconfiguration
        ->and(cashOf($run))->toBe(13 * 1_550)
        ->and($run->fresh()->config)->toEqual($expected);

    // From now on the new lap time and the new reward count.
    runService()->settle($run->id, 1_000_000 + 2 * lapMsOf($changed));

    expect(cashOf($run))->toBe(13 * 1_550 + 2 * 2_000)
        ->and($run->fresh()->laps_done)->toBe(15);
});

it('drops the lap in progress instead of finishing it with the new lap time', function () {
    $run = aRun(['fuel_ml' => 45_000]);

    // 13 laps end at 975 000; the 14th is 65 s old when the car is changed.
    $changed = runService()->reconfigure($run->id, dryInputs(stat: 130.0), 1_040_000);
    $newLap = lapMsOf($changed);

    runService()->settle($run->id, 1_040_000 + $newLap - 1);
    expect($run->fresh()->laps_done)->toBe(13);

    runService()->settle($run->id, 1_040_000 + $newLap);
    expect($run->fresh()->laps_done)->toBe(14);
});

it('writes nothing to the ledger when nothing was driven yet', function () {
    $run = aRun();

    runService()->reconfigure($run->id, dryInputs(), 10_000);

    expect(LedgerEntry::query()->count())->toBe(0)
        ->and(Settlement::query()->count())->toBe(0)
        ->and($run->fresh()->state_version)->toBe(1)
        ->and($run->fresh()->cursor_ms)->toBe(10_000);
});

it('does not pay twice when it is repeated at the same moment', function () {
    $run = aRun(['fuel_ml' => 45_000]);

    foreach ([1, 2, 3] as $_) {
        runService()->reconfigure($run->id, dryInputs(), 1_000_000);
    }

    expect(cashOf($run))->toBe(13 * 1_550)
        ->and(Settlement::query()->count())->toBe(1)
        ->and($run->fresh()->laps_done)->toBe(13);
});

it('keeps a stopped run stopped and where it stopped', function () {
    $run = aRun(['fuel_ml' => 3_000]);
    runService()->settle($run->id, 10_000_000);

    $stopped = $run->fresh();
    expect($stopped->status)->toBe('stopped')->and($stopped->cursor_ms)->toBe(150_000);

    $changed = runService()->reconfigure($run->id, dryInputs(stat: 130.0), 20_000_000);

    expect($changed->status)->toBe('stopped')
        ->and($changed->stop_reason)->toBe('fuel_empty')
        ->and($changed->cursor_ms)->toBe(150_000)
        ->and($changed->config['lap_cash_cents'])->toBe(['dry' => 2_000]);
});

it('keeps the seed, so saving the same setup again cannot re-roll the breakdown', function () {
    $user = User::factory()->guest()->create();
    $inputs = dryInputs(breakChancePpm: 50_000);
    $run = runService()->start($user->id, 'rdzak-gt-4', 'zlomowo-street', $inputs, 450_000, 1_000_000);

    $plan = $run->break_after_laps;
    expect($plan)->toBeInt();

    foreach (range(1, 5) as $_) {
        $again = runService()->reconfigure($run->id, $inputs, 1_000_000);

        expect($again->seed)->toBe($run->seed)
            ->and($again->break_after_laps)->toBe($plan);
    }
});

it('plans the breakdown from the laps already done, with the new chance', function () {
    $run = aRun(['fuel_ml' => 45_000, 'break_after_laps' => null]);

    $always = runService()->reconfigure($run->id, dryInputs(breakChancePpm: 1_000_000), 1_000_000);
    expect($always->break_after_laps)->toBe(14); // 13 laps were driven

    $never = runService()->reconfigure($run->id, dryInputs(breakChancePpm: 0), 1_000_000);
    expect($never->break_after_laps)->toBeNull();
});

it('stamps the version of the rules that the configuration was frozen with', function () {
    config(['ride.ruleset_version' => 'rules-2']);
    $run = aRun();

    $changed = runService()->reconfigure($run->id, dryInputs(), 1_000);

    expect($changed->ruleset_version)->toBe('rules-2');
});

it('undoes the settlement when freezing the new configuration cannot be saved', function () {
    $run = aRun(['fuel_ml' => 45_000]);

    // The first update of the run is the settlement, the second one the new configuration.
    $updates = 0;
    $event = 'eloquent.updating: '.RaceRun::class;
    Event::listen($event, static function () use (&$updates): void {
        if (++$updates === 2) {
            throw new RuntimeException('The disk is full.');
        }
    });

    try {
        expect(fn () => runService()->reconfigure($run->id, dryInputs(), 1_000_000))->toThrow(RuntimeException::class, 'The disk is full.');
    } finally {
        Event::forget($event);
    }

    $fresh = $run->fresh();

    expect($fresh->laps_done)->toBe(0)
        ->and($fresh->state_version)->toBe(0)
        ->and($fresh->config)->toEqual(RaceRunFactory::constantConfig())
        ->and(Settlement::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('fails for a run that does not exist', function () {
    runService()->reconfigure(999_999, dryInputs(), 1_000);
})->throws(ModelNotFoundException::class);

it('saves the track together with the new configuration', function () {
    $run = aRun(['fuel_ml' => 45_000]);

    $changed = runService()->reconfigure($run->id, dryInputs(rewardCents: 2_000), 1_000_000, 'another-track');

    expect($changed->track_slug)->toBe('another-track')
        ->and($run->fresh()->track_slug)->toBe('another-track')
        ->and($run->fresh()->config['lap_cash_cents'])->toBe(['dry' => 2_000]);
});

it('keeps the track when none is given', function () {
    $run = aRun();

    runService()->reconfigure($run->id, dryInputs(), 1_000);

    expect($run->fresh()->track_slug)->toBe('zlomowo-street');
});

it('keeps the old track when saving the new configuration fails', function () {
    $run = aRun(['fuel_ml' => 45_000]);

    $updates = 0;
    $event = 'eloquent.updating: '.RaceRun::class;
    Event::listen($event, static function () use (&$updates): void {
        if (++$updates === 2) {
            throw new RuntimeException('The disk is full.');
        }
    });

    try {
        expect(fn () => runService()->reconfigure($run->id, dryInputs(), 1_000_000, 'another-track'))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }

    expect($run->fresh()->track_slug)->toBe('zlomowo-street');
});
