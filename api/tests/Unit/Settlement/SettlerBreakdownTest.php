<?php

declare(strict_types=1);

use App\Domain\Settlement\EventType;
use App\Domain\Settlement\RunState;
use App\Domain\Settlement\SettlementEvent;
use App\Domain\Settlement\SettlementResult;
use App\Domain\Settlement\Settler;

/** @return list<array{type: string, at_ms: int}> type and time of every event, without payloads */
function timeline(SettlementResult $result): array
{
    return array_map(static fn (SettlementEvent $event): array => ['type' => $event->type->value, 'at_ms' => $event->atMs], $result->events);
}

function breakdownConfig(): App\Domain\Settlement\RunConfig
{
    return App\Domain\Settlement\RunConfig::constant(75_000, 1_550, 25, 1_500);
}

it('W4: a breakdown and an empty tank after the same lap are both logged, breakdown first', function () {
    $result = (new Settler())->settle(new RunState(0, 0, 45_000, 30), 3_600_000, breakdownConfig());

    expect($result->laps)->toBe(30)
        ->and($result->cashCents)->toBe(46_500)
        ->and($result->xp)->toBe(750)
        ->and($result->state->cursorMs)->toBe(2_250_000)
        ->and($result->state->fuelMl)->toBe(0)
        ->and(array_slice(timeline($result), -2))->toBe([
            ['type' => 'breakdown', 'at_ms' => 2_250_000],
            ['type' => 'fuel_empty', 'at_ms' => 2_250_000],
        ])
        ->and($result->stopReason())->toBe(EventType::Breakdown);
});

it('pays the lap after which the car breaks down and drives no further', function () {
    $result = (new Settler())->settle(new RunState(0, 0, 450_000, 10), 3_600_000, breakdownConfig());

    expect($result->laps)->toBe(10)
        ->and($result->cashCents)->toBe(15_500)
        ->and($result->state->cursorMs)->toBe(750_000)
        ->and($result->state->fuelMl)->toBe(435_000)
        ->and(timeline($result))->toBe([
            ['type' => 'lap_batch', 'at_ms' => 750_000],
            ['type' => 'breakdown', 'at_ms' => 750_000],
        ]);
});

it('counts the planned breakdown from laps already driven', function () {
    $result = (new Settler())->settle(new RunState(0, 8, 450_000, 10), 3_600_000, breakdownConfig());

    expect($result->laps)->toBe(2)
        ->and($result->state->lapsDone)->toBe(10)
        ->and($result->stopReason())->toBe(EventType::Breakdown);
});

it('does not repeat the breakdown for a car that is already broken', function () {
    $state = new RunState(750_000, 10, 435_000, 10);

    $result = (new Settler())->settle($state, 9_000_000, breakdownConfig());

    expect($result->isEmpty())->toBeTrue()
        ->and($result->stopReason())->toBeNull()
        ->and($result->state)->toEqual($state);
});

it('does not report a breakdown that has not happened yet', function () {
    $result = (new Settler())->settle(new RunState(0, 0, 450_000, 40), 750_000, breakdownConfig());

    expect($result->laps)->toBe(10)
        ->and($result->stopReason())->toBeNull();
});

it('reports an empty tank when the fuel ends before the planned breakdown', function () {
    $result = (new Settler())->settle(new RunState(0, 0, 4_500, 50), 3_600_000, breakdownConfig());

    expect($result->laps)->toBe(3)
        ->and($result->stopReason())->toBe(EventType::FuelEmpty)
        ->and(array_column(timeline($result), 'type'))->toBe(['lap_batch', 'fuel_empty']);
});

it('keeps events in time order across segments', function () {
    $result = (new Settler())->settle(new RunState(0, 0, 1_500_000, 40), 10_000_000, breakdownConfig());

    $times = array_column(timeline($result), 'at_ms');
    $sorted = $times;
    sort($sorted);

    expect($times)->toBe($sorted)
        ->and(timeline($result)[array_key_last(timeline($result))]['type'])->toBe('breakdown');
});

it('puts a lap payment before a breakdown that happens at the same moment', function () {
    $result = (new Settler())->settle(new RunState(0, 0, 450_000, 18), 3_600_000, breakdownConfig());

    // 18 laps end exactly at the segment boundary 1 350 000.
    expect(timeline($result))->toBe([
        ['type' => 'lap_batch', 'at_ms' => 1_350_000],
        ['type' => 'breakdown', 'at_ms' => 1_350_000],
    ]);
});
