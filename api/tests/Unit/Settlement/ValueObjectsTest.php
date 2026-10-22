<?php

declare(strict_types=1);

use App\Domain\Settlement\CallbackWeather;
use App\Domain\Settlement\ConstantWeather;
use App\Domain\Settlement\EventType;
use App\Domain\Settlement\RunConfig;
use App\Domain\Settlement\RunState;
use App\Domain\Settlement\SettlementEvent;

it('rejects a negative run state', function (int $cursor, int $laps, int $fuel, ?int $breakAfter) {
    new RunState($cursor, $laps, $fuel, $breakAfter);
})->throws(InvalidArgumentException::class)->with([
    'cursor' => [-1, 0, 0, null],
    'laps' => [0, -1, 0, null],
    'fuel' => [0, 0, -1, null],
    'breakdown lap' => [0, 0, 0, -1],
]);

it('copies a run state with changes and keeps the breakdown lap', function () {
    $state = new RunState(10, 2, 3000, 30);
    $next = $state->with(cursorMs: 99, fuelMl: 1500);

    expect($next->cursorMs)->toBe(99)
        ->and($next->lapsDone)->toBe(2)
        ->and($next->fuelMl)->toBe(1500)
        ->and($next->breakAfterLaps)->toBe(30)
        ->and($state->cursorMs)->toBe(10);
});

it('builds a constant run configuration', function () {
    $config = RunConfig::constant(75_000, 1_550, 25, 1_500);

    expect($config->lapMsFor('dry'))->toBe(75_000)
        ->and($config->lapCashFor('dry'))->toBe(1_550)
        ->and($config->lapXpFor('dry'))->toBe(25)
        ->and($config->fuelPerLapMl)->toBe(1_500)
        ->and($config->weather->at(12345))->toBe('dry');
});

it('rejects an invalid run configuration', function (array $lapMs, array $cash, array $xp, int $fuel) {
    new RunConfig($lapMs, $cash, $xp, $fuel, new ConstantWeather());
})->throws(InvalidArgumentException::class)->with([
    'no weather' => [[], [], [], 1500],
    'zero lap time' => [['dry' => 0], ['dry' => 1], ['dry' => 1], 1500],
    'negative reward' => [['dry' => 1000], ['dry' => -1], ['dry' => 1], 1500],
    'negative XP' => [['dry' => 1000], ['dry' => 1], ['dry' => -1], 1500],
    'zero fuel per lap' => [['dry' => 1000], ['dry' => 1], ['dry' => 1], 0],
    'different weathers' => [['dry' => 1000, 'rain' => 1100], ['dry' => 1], ['dry' => 1], 1500],
]);

it('accepts the same weathers in a different order', function () {
    $config = new RunConfig(
        ['dry' => 1000, 'rain' => 1100],
        ['rain' => 5, 'dry' => 6],
        ['rain' => 1, 'dry' => 2],
        1500,
        new ConstantWeather(),
    );

    expect($config->lapCashFor('rain'))->toBe(5);
});

it('fails loudly for a weather without a lap time', function () {
    RunConfig::constant(75_000, 1_550, 25, 1_500)->lapMsFor('snow');
})->throws(InvalidArgumentException::class);

it('asks a weather schedule for the segment', function () {
    $weather = new CallbackWeather(fn (int $segment): string => $segment % 2 === 0 ? 'dry' : 'rain');

    expect($weather->at(0))->toBe('dry')
        ->and($weather->at(1))->toBe('rain');
});

it('orders event types by priority', function () {
    expect(EventType::LapBatch->priority())->toBeLessThan(EventType::Breakdown->priority())
        ->and(EventType::Breakdown->priority())->toBeLessThan(EventType::FuelEmpty->priority());
});

it('serializes events for the log', function () {
    $batch = SettlementEvent::lapBatch(0, 'dry', 18, 27_900, 450, 0, 1_350_000);

    expect($batch->toArray())->toBe([
        'type' => 'lap_batch',
        'at_ms' => 1_350_000,
        'payload' => [
            'segment' => 0,
            'weather' => 'dry',
            'laps' => 18,
            'cash_cents' => 27_900,
            'xp' => 450,
            'from_ms' => 0,
            'to_ms' => 1_350_000,
        ],
    ])->and(SettlementEvent::breakdown(5)->toArray())->toBe(['type' => 'breakdown', 'at_ms' => 5, 'payload' => []])
        ->and(SettlementEvent::fuelEmpty(7)->priority())->toBe(4);
});
