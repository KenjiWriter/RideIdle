<?php

declare(strict_types=1);

use App\Domain\Run\RunFreezer;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Ledger;
use Database\Factories\RaceRunFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;

/**
 * A run that is planned to break down after its second lap (a lap is 75 s), optionally with money to spend.
 *
 * @param  array<string, mixed>  $attributes
 */
function brokenRun(array $attributes = [], int $cents = 0): RaceRun
{
    return fundedRun($cents, [...['fuel_ml' => 45_000, 'break_after_laps' => 2], ...$attributes]);
}

it('repairs a run that broke down: new seed, new plan, driving again from now', function () {
    $run = brokenRun();
    $oldSeed = $run->seed;
    $inputs = dryInputs(breakChancePpm: 50_000);

    $repaired = runService()->repair($run->id, $inputs, 1_000_000);

    $fresh = $run->fresh();
    $expected = (new RunFreezer())->freeze($inputs, $fresh->seed, 2);

    expect($repaired->repaired)->toBeTrue()
        ->and($fresh->seed)->not->toBe($oldSeed)
        ->and($fresh->seed)->toMatch('/^[a-f0-9]{32}$/')
        ->and($fresh->break_after_laps)->toBe($expected->breakAfterLaps)
        ->and($fresh->break_after_laps)->toBeGreaterThan(2)
        ->and($fresh->config)->toEqual($expected->config)
        ->and($fresh->status)->toBe('running')
        ->and($fresh->stop_reason)->toBeNull()
        ->and($fresh->cursor_ms)->toBe(1_000_000)
        ->and($fresh->laps_done)->toBe(2)
        ->and($fresh->state_version)->toBe(2) // the settlement of the two laps and the repair
        ->and($repaired->run->is($run))->toBeTrue();
});

it('pays the laps before the breakdown with the old configuration', function () {
    $run = brokenRun();

    runService()->repair($run->id, dryInputs(rewardCents: 2_000), 1_000_000);

    expect(cashOf($run))->toBe(2 * 1_550)
        ->and(Settlement::query()->where('race_run_id', $run->id)->value('stop_reason'))->toBe('breakdown');
});

it('drives again from now, not from where the car broke down', function () {
    $run = brokenRun();
    runService()->repair($run->id, dryInputs(), 1_000_000);

    // The 850 s of standing are not paid: two laps in the next 150 s, at the new reward.
    runService()->settle($run->id, 1_000_000 + 150_000);

    expect($run->fresh()->laps_done)->toBe(4)
        ->and(cashOf($run))->toBe(2 * 1_550 + 2 * 2_000);
});

it('breaks down again at the lap of the new plan', function () {
    $run = brokenRun();
    $inputs = dryInputs(breakChancePpm: 1_000_000); // every lap is a hit, so the plan is the very next lap

    runService()->repair($run->id, $inputs, 1_000_000);
    expect($run->fresh()->break_after_laps)->toBe(3);

    runService()->settle($run->id, 5_000_000);

    $fresh = $run->fresh();
    expect($fresh->laps_done)->toBe(3)
        ->and($fresh->status)->toBe('stopped')
        ->and($fresh->stop_reason)->toBe('breakdown');
});

it('leaves a run that is driving alone, so a repair cannot re-roll the plan', function () {
    $run = aRun(['fuel_ml' => 45_000, 'break_after_laps' => 40]);
    $seed = $run->seed;

    $repaired = runService()->repair($run->id, dryInputs(breakChancePpm: 50_000), 1_000_000);

    $fresh = $run->fresh();

    expect($repaired->repaired)->toBeFalse()
        ->and($fresh->seed)->toBe($seed)
        ->and($fresh->break_after_laps)->toBe(40)
        ->and($fresh->config)->toEqual(RaceRunFactory::constantConfig())
        ->and($fresh->state_version)->toBe(1); // only the settlement
});

it('leaves a run that only ran out of fuel stopped', function () {
    $run = aRun(['fuel_ml' => 3_000]);

    $repaired = runService()->repair($run->id, dryInputs(), 1_000_000);

    $fresh = $run->fresh();

    expect($repaired->repaired)->toBeFalse()
        ->and($fresh->status)->toBe('stopped')
        ->and($fresh->stop_reason)->toBe('fuel_empty')
        ->and($fresh->config)->toEqual(RaceRunFactory::constantConfig());
});

it('repairs once when the same repair is asked for again', function () {
    $run = brokenRun();
    $inputs = dryInputs(breakChancePpm: 50_000);

    $first = runService()->repair($run->id, $inputs, 1_000_000);
    $seed = $run->fresh()->seed;
    $again = runService()->repair($run->id, $inputs, 1_000_000);

    expect($first->repaired)->toBeTrue()
        ->and($again->repaired)->toBeFalse()
        ->and($run->fresh()->seed)->toBe($seed)
        ->and($run->fresh()->state_version)->toBe(2);
});

it('keeps a run without fuel stopped, and a refuel starts it', function () {
    // Two laps use all the fuel at the very lap of the breakdown.
    $run = brokenRun(['fuel_ml' => 3_000]);

    $repaired = runService()->repair($run->id, dryInputs(), 1_000_000);

    $fresh = $run->fresh();
    expect($repaired->repaired)->toBeTrue()
        ->and($fresh->status)->toBe('stopped')
        ->and($fresh->stop_reason)->toBe('fuel_empty')
        ->and($fresh->fuel_ml)->toBe(0);

    runService()->refuel($run->id, 'r1', 45_000, 150, 10_000, 2_000_000);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe('running')
        ->and($fresh->stop_reason)->toBeNull()
        ->and($fresh->cursor_ms)->toBe(2_000_000);
});

it('stamps the version of the rules that the new configuration was frozen with', function () {
    config(['ride.ruleset_version' => 'rules-3']);
    $run = brokenRun();

    runService()->repair($run->id, dryInputs(), 1_000_000);

    expect($run->fresh()->ruleset_version)->toBe('rules-3');
});

it('undoes the settlement when the repair cannot be saved', function () {
    $run = brokenRun();
    $seed = $run->seed;

    // The first update of the run is the settlement, the second one the repair.
    $updates = 0;
    $event = 'eloquent.updating: '.RaceRun::class;
    Event::listen($event, static function () use (&$updates): void {
        if (++$updates === 2) {
            throw new RuntimeException('The disk is full.');
        }
    });

    try {
        expect(fn () => runService()->repair($run->id, dryInputs(), 1_000_000))->toThrow(RuntimeException::class, 'The disk is full.');
    } finally {
        Event::forget($event);
    }

    $fresh = $run->fresh();

    expect($fresh->seed)->toBe($seed)
        ->and($fresh->laps_done)->toBe(0)
        ->and($fresh->state_version)->toBe(0)
        ->and(Settlement::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('fails for a run that does not exist', function () {
    runService()->repair(999_999, dryInputs(), 1_000);
})->throws(ModelNotFoundException::class);

it('charges the ledger for a repair', function () {
    $run = brokenRun(cents: 10_000);

    $repaired = runService()->repair($run->id, dryInputs(), 1_000_000, 2_500, 'r1');

    $entry = LedgerEntry::query()->where('reason', 'repair')->firstOrFail();

    expect($repaired->repaired)->toBeTrue()
        ->and($repaired->costCents)->toBe(2_500)
        ->and($repaired->repeated)->toBeFalse()
        ->and($entry->delta)->toBe(-2_500)
        ->and($entry->idem_key)->toBe("repair:{$run->id}:r1")
        ->and(cashOf($run))->toBe(10_000 + 2 * 1_550 - 2_500)
        ->and((new Ledger())->isConsistent($run->user_id))->toBeTrue()
        ->and($run->fresh()->status)->toBe('running');
});

it('pays for the repair with what the car earned before it broke down', function () {
    $run = brokenRun();

    runService()->repair($run->id, dryInputs(), 1_000_000, 2_000, 'r1');

    expect(cashOf($run))->toBe(2 * 1_550 - 2_000);
});

it('changes nothing when the driver cannot pay, not even the settlement', function () {
    $run = brokenRun();
    $seed = $run->seed;

    // The two laps earn $31.00, the repair costs $50.00.
    expect(fn () => runService()->repair($run->id, dryInputs(), 1_000_000, 5_000, 'r1'))->toThrow(InsufficientFundsException::class);

    $fresh = $run->fresh();

    expect($fresh->seed)->toBe($seed)
        ->and($fresh->status)->toBe('running')
        ->and($fresh->laps_done)->toBe(0)
        ->and($fresh->state_version)->toBe(0)
        ->and(Settlement::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('does not charge for a repair that does not happen', function () {
    $run = fundedRun(10_000, ['fuel_ml' => 45_000]);

    $repaired = runService()->repair($run->id, dryInputs(), 1_000_000, 2_500, 'r1');

    expect($repaired->repaired)->toBeFalse()
        ->and($repaired->costCents)->toBe(0)
        ->and(LedgerEntry::query()->where('reason', 'repair')->count())->toBe(0);
});

it('charges once for the same request, even when the car broke down again', function () {
    $run = brokenRun(cents: 10_000);
    $inputs = dryInputs(breakChancePpm: 1_000_000); // the next lap after a repair is a breakdown again

    runService()->repair($run->id, $inputs, 1_000_000, 500, 'r1');
    runService()->settle($run->id, 3_000_000);
    expect($run->fresh()->stop_reason)->toBe('breakdown');

    $again = runService()->repair($run->id, $inputs, 3_000_000, 500, 'r1');

    expect($again->repeated)->toBeTrue()
        ->and($again->repaired)->toBeFalse()
        ->and($run->fresh()->status)->toBe('stopped')
        ->and(LedgerEntry::query()->where('reason', 'repair')->count())->toBe(1);

    // Another request id is another intention to repair.
    $next = runService()->repair($run->id, $inputs, 3_000_000, 500, 'r2');

    expect($next->repaired)->toBeTrue()
        ->and($run->fresh()->status)->toBe('running')
        ->and(LedgerEntry::query()->where('reason', 'repair')->count())->toBe(2);
});

it('refuses a repair request id that already belongs to another driver', function () {
    $run = brokenRun(cents: 10_000);
    $other = User::factory()->guest()->create();
    (new Ledger())->post($other->id, 1_000, 'gift', 'seed');
    (new Ledger())->post($other->id, -100, 'repair', "repair:{$run->id}:r1");

    expect(fn () => runService()->repair($run->id, dryInputs(), 1_000_000, 500, 'r1'))->toThrow(IdempotencyConflictException::class);
    expect($run->fresh()->state_version)->toBe(0);
});

it('rejects an invalid cost or request id', function (int $cost, ?string $requestId) {
    $run = brokenRun(cents: 10_000);

    runService()->repair($run->id, dryInputs(), 1_000_000, $cost, $requestId);
})->throws(InvalidArgumentException::class)->with([
    'negative cost' => [-1, 'r1'],
    'paid repair without a request id' => [500, null],
    'paid repair with an empty request id' => [500, ''],
    'request id too long' => [500, str_repeat('x', 65)],
]);
