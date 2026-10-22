<?php

declare(strict_types=1);

use App\Domain\Settlement\CallbackWeather;
use App\Domain\Settlement\RunConfig;
use App\Domain\Settlement\RunState;
use App\Domain\Settlement\SettlementEvent;
use App\Domain\Settlement\SettlementResult;
use App\Domain\Settlement\Settler;

/*
| Test vectors from the settlement specification (W1-W3). Common parameters unless stated:
| lap 75 000 ms, reward 1 550 cents, 25 XP, 1 500 ml of fuel per lap, cursor at 0.
*/

function standardConfig(): RunConfig
{
    return RunConfig::constant(75_000, 1_550, 25, 1_500);
}

/** @return list<array{type: string, at_ms: int, payload: array<string, int|string>}> */
function logOf(SettlementResult $result): array
{
    return array_map(static fn (SettlementEvent $event): array => $event->toArray(), $result->events);
}

/** A lap batch as it appears in the log, for the dry weather unless stated. */
function batch(int $segment, int $laps, int $cash, int $xp, int $from, int $to, string $weather = 'dry'): array
{
    return [
        'type' => 'lap_batch',
        'at_ms' => $to,
        'payload' => [
            'segment' => $segment,
            'weather' => $weather,
            'laps' => $laps,
            'cash_cents' => $cash,
            'xp' => $xp,
            'from_ms' => $from,
            'to_ms' => $to,
        ],
    ];
}

it('W1: the fuel limits the drive', function () {
    $result = (new Settler())->settle(new RunState(0, 0, 45_000), 3_600_000, standardConfig());

    expect($result->laps)->toBe(30)
        ->and($result->cashCents)->toBe(46_500)
        ->and($result->xp)->toBe(750)
        ->and($result->state->cursorMs)->toBe(2_250_000)
        ->and($result->state->fuelMl)->toBe(0)
        ->and($result->state->lapsDone)->toBe(30)
        ->and(logOf($result))->toBe([
            batch(0, 18, 27_900, 450, 0, 1_350_000),
            batch(1, 12, 18_600, 300, 1_350_000, 2_250_000),
            ['type' => 'fuel_empty', 'at_ms' => 2_250_000, 'payload' => []],
        ])
        ->and($result->stopReason()?->value)->toBe('fuel_empty');
});

it('W2a: a lap in progress is neither paid nor lost', function () {
    $result = (new Settler())->settle(new RunState(0, 0, 60_000), 2_310_000, standardConfig());

    // Lap 31 would finish at 2 325 000, after `now`.
    expect($result->laps)->toBe(30)
        ->and($result->cashCents)->toBe(46_500)
        ->and($result->state->cursorMs)->toBe(2_250_000)
        ->and($result->state->fuelMl)->toBe(15_000)
        ->and($result->stopReason())->toBeNull();
});

it('W2b: settling again continues from the cursor', function () {
    $first = (new Settler())->settle(new RunState(0, 0, 60_000), 2_310_000, standardConfig());
    $second = (new Settler())->settle($first->state, 2_325_000, standardConfig());

    expect($second->laps)->toBe(1)
        ->and($second->cashCents)->toBe(1_550)
        ->and($second->xp)->toBe(25)
        ->and($second->state->cursorMs)->toBe(2_325_000)
        ->and($second->state->fuelMl)->toBe(13_500)
        ->and($first->cashCents + $second->cashCents)->toBe(48_050)
        ->and(logOf($second))->toBe([batch(1, 1, 1_550, 25, 2_250_000, 2_325_000)]);
});

it('W2c: one settlement equals the two steps of W2a and W2b', function () {
    $result = (new Settler())->settle(new RunState(0, 0, 60_000), 2_325_000, standardConfig());

    expect($result->laps)->toBe(31)
        ->and($result->cashCents)->toBe(48_050)
        ->and($result->xp)->toBe(775)
        ->and($result->state->cursorMs)->toBe(2_325_000)
        ->and($result->state->fuelMl)->toBe(13_500)
        ->and(logOf($result))->toBe([
            batch(0, 18, 27_900, 450, 0, 1_350_000),
            batch(1, 13, 20_150, 325, 1_350_000, 2_325_000),
        ]);
});

it('W3: a lap that starts before a weather change keeps its lap time', function () {
    $config = new RunConfig(
        ['dry' => 80_000, 'rain' => 90_000],
        ['dry' => 1_550, 'rain' => 1_550],
        ['dry' => 25, 'rain' => 25],
        1_500,
        new CallbackWeather(fn (int $segment): string => $segment === 0 ? 'dry' : 'rain'),
    );

    $result = (new Settler())->settle(new RunState(0, 0, 100_000), 2_700_000, $config);

    // Segment 0: 17 laps, the last starts at 1 280 000 and ends at 1 360 000, after the boundary.
    // Segment 1: 14 laps of 90 000 ms, and 80 000 ms of the next lap are still in progress.
    expect($result->laps)->toBe(31)
        ->and($result->cashCents)->toBe(48_050)
        ->and($result->xp)->toBe(775)
        ->and($result->state->cursorMs)->toBe(2_620_000)
        ->and($result->state->fuelMl)->toBe(53_500)
        ->and(logOf($result))->toBe([
            batch(0, 17, 26_350, 425, 0, 1_360_000),
            batch(1, 14, 21_700, 350, 1_360_000, 2_620_000, 'rain'),
        ]);
});

it('counts a lap that finishes exactly at now', function () {
    $result = (new Settler())->settle(new RunState(0, 0, 10_000), 75_000, standardConfig());

    expect($result->laps)->toBe(1)
        ->and($result->state->cursorMs)->toBe(75_000);
});

it('does nothing when now is not later than the cursor', function (int $now) {
    $state = new RunState(1_000_000, 5, 30_000);
    $result = (new Settler())->settle($state, $now, standardConfig());

    expect($result->isEmpty())->toBeTrue()
        ->and($result->state)->toEqual($state);
})->with([
    'before the cursor' => [999_999],
    'at the cursor' => [1_000_000],
]);

it('does nothing without fuel and does not announce an empty tank again', function () {
    $result = (new Settler())->settle(new RunState(0, 30, 1_499), 3_600_000, standardConfig());

    expect($result->isEmpty())->toBeTrue()
        ->and($result->state->cursorMs)->toBe(0);
});

it('works with a real epoch timestamp', function () {
    // 2026-10-22 18:00:00 UTC in milliseconds: the segment number is large but the formula is the same.
    $start = 1_792_692_000_000;
    $result = (new Settler())->settle(new RunState($start, 0, 45_000), $start + 3_600_000, standardConfig());

    expect($result->laps)->toBe(30)
        ->and($result->state->cursorMs)->toBe($start + 2_250_000);
});

it('asks the weather schedule for the segment of the lap start', function () {
    $asked = [];
    $weather = new CallbackWeather(function (int $segment) use (&$asked): string {
        $asked[] = $segment;

        return 'dry';
    });
    $config = new RunConfig(['dry' => 75_000], ['dry' => 1_550], ['dry' => 25], 1_500, $weather);

    (new Settler())->settle(new RunState(1_000_000, 0, 100_000), 4_000_000, $config);

    // 1 000 000 ms is in segment 0, the following laps start in segments 0, 1 and 2.
    expect($asked)->toBe([0, 1, 2]);
});
