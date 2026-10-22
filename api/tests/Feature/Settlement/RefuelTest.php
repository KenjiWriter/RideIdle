<?php

declare(strict_types=1);

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Ledger;
use App\Services\Refueled;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;

const TANK_ML = 45_000;
const PRICE_PER_LITER = 150;

/** Gives the driver of a run some money to spend. */
function fundedRun(int $cents, array $attributes = []): RaceRun
{
    $run = aRun($attributes);

    if ($cents > 0) {
        (new Ledger())->post($run->user_id, $cents, 'gift', 'seed-money:'.$run->id);
    }

    return $run;
}

function refuelOf(RaceRun $run, string $requestId = 'r1', ?int $addMl = null, int $now = 0, int $price = PRICE_PER_LITER): Refueled
{
    return runService()->refuel($run->id, $requestId, TANK_ML, $price, $addMl, $now);
}

it('fills the tank and charges the ledger', function () {
    $run = fundedRun(100_000, ['fuel_ml' => 15_000]);

    $refueled = refuelOf($run);

    expect($refueled->addedMl)->toBe(30_000)
        ->and($refueled->costCents)->toBe(4_500) // 30 l at 1.50
        ->and($refueled->repeated)->toBeFalse()
        ->and($refueled->run->fuel_ml)->toBe(45_000)
        ->and($run->fresh()->fuel_ml)->toBe(45_000)
        ->and($run->fresh()->state_version)->toBe(1)
        ->and($run->fresh()->status)->toBe('running')
        ->and(cashOf($run))->toBe(95_500);

    $entry = LedgerEntry::query()->where('reason', 'refuel')->firstOrFail();

    expect($entry->delta)->toBe(-4_500)
        ->and($entry->idem_key)->toBe("refuel:{$run->id}:r1")
        ->and((new Ledger())->isConsistent($run->user_id))->toBeTrue();
});

it('adds the amount asked for', function () {
    $run = fundedRun(100_000, ['fuel_ml' => 15_000]);

    $refueled = refuelOf($run, addMl: 10_000);

    expect($refueled->addedMl)->toBe(10_000)
        ->and($refueled->costCents)->toBe(1_500)
        ->and($run->fresh()->fuel_ml)->toBe(25_000);
});

it('rounds the price up to a whole cent', function (int $addMl, int $expectedCents) {
    $run = fundedRun(100_000, ['fuel_ml' => 0]);

    expect(refuelOf($run, addMl: $addMl)->costCents)->toBe($expectedCents);
})->with([
    'one millilitre' => [1, 1],
    'exactly whole cents' => [20, 3], // 20 ml at 1.50 per liter is 3.0 cents
    'a bit over' => [1_001, 151],
    'a whole liter' => [1_000, 150],
]);

it('adds only what fits in the tank', function () {
    $run = fundedRun(100_000, ['fuel_ml' => 40_000]);

    $refueled = refuelOf($run, addMl: 20_000);

    expect($refueled->addedMl)->toBe(5_000)
        ->and($refueled->costCents)->toBe(750)
        ->and($run->fresh()->fuel_ml)->toBe(TANK_ML);
});

it('does nothing and charges nothing when the tank is full', function () {
    $run = fundedRun(100_000, ['fuel_ml' => TANK_ML]);

    $refueled = refuelOf($run);

    expect($refueled->addedMl)->toBe(0)
        ->and($refueled->costCents)->toBe(0)
        ->and($run->fresh()->state_version)->toBe(0)
        ->and(LedgerEntry::query()->where('reason', 'refuel')->count())->toBe(0)
        ->and(cashOf($run))->toBe(100_000);
});

it('settles first, so the money earned while driving pays for the fuel', function () {
    // Four laps of fuel, no money: the car drives 4 laps ($62.00), runs dry and stands until 600 000.
    $run = fundedRun(0, ['fuel_ml' => 6_000]);

    $refueled = refuelOf($run, addMl: 10_000, now: 600_000);

    $fresh = $run->fresh();

    expect($refueled->costCents)->toBe(1_500)
        ->and(cashOf($run))->toBe(4 * 1_550 - 1_500)
        ->and($fresh->laps_done)->toBe(4)
        ->and($fresh->fuel_ml)->toBe(10_000)
        ->and($fresh->state_version)->toBe(2) // the settlement and the refuel
        ->and(Settlement::query()->count())->toBe(1);
});

it('drives again from now, not from where the car ran dry', function () {
    $run = fundedRun(0, ['fuel_ml' => 6_000]);

    refuelOf($run, addMl: 10_000, now: 600_000);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe('running')
        ->and($fresh->stop_reason)->toBeNull()
        ->and($fresh->cursor_ms)->toBe(600_000);

    // The 300 s of standing are not paid: two laps in the next 150 s, not eight.
    runService()->settle($run->id, 600_000 + 150_000);

    expect($run->fresh()->laps_done)->toBe(6)
        ->and(cashOf($run))->toBe(6 * 1_550 - 1_500);
});

it('drives from now when the run never had fuel for a lap', function () {
    $run = fundedRun(10_000, ['fuel_ml' => 1_000]);

    refuelOf($run, addMl: 2_000, now: 5_000_000);

    expect($run->fresh()->cursor_ms)->toBe(5_000_000);

    runService()->settle($run->id, 5_000_000 + 75_000);

    expect($run->fresh()->laps_done)->toBe(1);
});

it('keeps a driving run where it is', function () {
    $run = fundedRun(100_000, ['fuel_ml' => 30_000]);

    refuelOf($run, addMl: 10_000, now: 1_000_000);

    // 13 laps were driven by then; the lap in progress is not lost by buying fuel.
    expect($run->fresh()->laps_done)->toBe(13)
        ->and($run->fresh()->cursor_ms)->toBe(975_000)
        ->and($run->fresh()->status)->toBe('running')
        ->and($run->fresh()->fuel_ml)->toBe(30_000 - 13 * 1_500 + 10_000);
});

it('does not restart a run that broke down', function () {
    $run = fundedRun(100_000, ['fuel_ml' => 45_000, 'break_after_laps' => 2]);

    $refueled = refuelOf($run, addMl: 3_000, now: 1_000_000);

    $fresh = $run->fresh();

    expect($refueled->addedMl)->toBe(3_000)
        ->and($fresh->status)->toBe('stopped')
        ->and($fresh->stop_reason)->toBe('breakdown')
        ->and($fresh->cursor_ms)->toBe(150_000)
        ->and($fresh->fuel_ml)->toBe(42_000 + 3_000);
});

it('changes nothing when the driver cannot pay, not even the settlement', function () {
    $run = fundedRun(0, ['fuel_ml' => 6_000]);

    // 4 laps earn $62.00, but a full tank of 45 l at $4.00 costs $180.00.
    expect(fn () => refuelOf($run, now: 600_000, price: 400))->toThrow(InsufficientFundsException::class);

    $fresh = $run->fresh();

    expect($fresh->fuel_ml)->toBe(6_000)
        ->and($fresh->laps_done)->toBe(0)
        ->and($fresh->state_version)->toBe(0)
        ->and(Settlement::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and(cashOf($run))->toBe(0);

    // The laps are still there to be settled later.
    runService()->settle($run->id, 600_000);
    expect(cashOf($run))->toBe(4 * 1_550);
});

it('undoes the settlement when the charge fails halfway', function () {
    $run = fundedRun(0, ['fuel_ml' => 6_000]);

    // The first ledger entry is the settlement, the second one the charge for the fuel.
    $entries = 0;
    $event = 'eloquent.creating: '.LedgerEntry::class;
    Event::listen($event, static function () use (&$entries): void {
        if (++$entries === 2) {
            throw new RuntimeException('The ledger is down.');
        }
    });

    try {
        expect(fn () => refuelOf($run, addMl: 10_000, now: 600_000))->toThrow(RuntimeException::class, 'The ledger is down.');
    } finally {
        Event::forget($event);
    }

    expect($run->fresh()->fuel_ml)->toBe(6_000)
        ->and($run->fresh()->laps_done)->toBe(0)
        ->and(Settlement::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('charges and fills once when the same request arrives again', function () {
    $run = fundedRun(100_000, ['fuel_ml' => 15_000]);

    // The tank still has room after the first one, so only the request id stops the second.
    $first = refuelOf($run, addMl: 10_000);
    $again = refuelOf($run, addMl: 10_000);

    expect($first->repeated)->toBeFalse()
        ->and($again->repeated)->toBeTrue()
        ->and($again->addedMl)->toBe(0)
        ->and($again->costCents)->toBe(0)
        ->and($again->run->fuel_ml)->toBe(25_000)
        ->and($run->fresh()->fuel_ml)->toBe(25_000)
        ->and($run->fresh()->state_version)->toBe(1)
        ->and(LedgerEntry::query()->where('reason', 'refuel')->count())->toBe(1)
        ->and(cashOf($run))->toBe(98_500);
});

it('treats another request id as another refuel', function () {
    $run = fundedRun(100_000, ['fuel_ml' => 15_000]);

    refuelOf($run, 'first', addMl: 10_000);
    refuelOf($run, 'second', addMl: 10_000);

    expect($run->fresh()->fuel_ml)->toBe(35_000)
        ->and(cashOf($run))->toBe(100_000 - 2 * 1_500);
});

it('refuses a request id that already belongs to another driver', function () {
    $run = fundedRun(100_000, ['fuel_ml' => 15_000]);
    $other = User::factory()->guest()->create();
    (new Ledger())->post($other->id, 1_000, 'gift', 'seed');
    (new Ledger())->post($other->id, -100, 'refuel', "refuel:{$run->id}:r1");

    expect(fn () => refuelOf($run))->toThrow(IdempotencyConflictException::class);
    expect($run->fresh()->fuel_ml)->toBe(15_000);
});

it('rejects invalid requests', function (string $requestId, int $tank, int $price, ?int $add) {
    $run = fundedRun(1_000);

    runService()->refuel($run->id, $requestId, $tank, $price, $add, 0);
})->throws(InvalidArgumentException::class)->with([
    'no request id' => ['', 45_000, 150, null],
    'request id too long' => [str_repeat('x', 65), 45_000, 150, null],
    'no tank' => ['r', 0, 150, null],
    'free fuel' => ['r', 45_000, 0, null],
    'nothing to add' => ['r', 45_000, 150, 0],
    'negative amount' => ['r', 45_000, 150, -1],
]);

it('fails for a run that does not exist', function () {
    runService()->refuel(999_999, 'r1', TANK_ML, PRICE_PER_LITER, null, 0);
})->throws(ModelNotFoundException::class);
