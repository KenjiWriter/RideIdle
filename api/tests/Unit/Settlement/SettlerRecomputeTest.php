<?php

declare(strict_types=1);

use App\Domain\Settlement\CallbackWeather;
use App\Domain\Settlement\EventType;
use App\Domain\Settlement\RunConfig;
use App\Domain\Settlement\RunState;
use App\Domain\Settlement\Settler;
use Random\Engine\Mt19937;
use Random\Randomizer;

// The lap at which the configuration has to be made again (a part wears to its next stage, the service moves on) ends a settlement like a planned
// breakdown does, with an event that is no stop of the car.

/**
 * Settles the way the caller of the settler does: when a pass ends where the configuration has to be made again, it plans the next such lap and settles on.
 * The configuration does not change here, so the result has to be the one of a settlement with no such lap at all.
 */
function settleThroughRecomputes(RunState $state, int $nowMs, RunConfig $config, int $every): array
{
    $settler = new Settler;
    $laps = $cash = $xp = 0;
    $events = [];

    for ($pass = 0; $pass < 100_000; $pass++) {
        $result = $settler->settleInChunks($state, $nowMs, $config);
        $laps += $result->laps;
        $cash += $result->cashCents;
        $xp += $result->xp;
        array_push($events, ...$result->events);
        $state = $result->state;

        if (! $result->needsRecompute()) {
            break;
        }

        $state = new RunState($state->cursorMs, $state->lapsDone, $state->fuelMl, $state->breakAfterLaps, $state->lapsDone + $every);
    }

    return ['state' => $state, 'laps' => $laps, 'cash' => $cash, 'xp' => $xp, 'events' => $events];
}

it('stops at the lap at which the configuration has to be made again, with an event that is no stop of the car', function () {
    $config = RunConfig::constant(75_000, 1_550, 25, 1_500);
    $result = (new Settler)->settle(new RunState(0, 0, 45_000, null, 10), 3_600_000, $config);

    expect($result->laps)->toBe(10)
        ->and($result->state->lapsDone)->toBe(10)
        ->and($result->state->cursorMs)->toBe(750_000)
        ->and($result->needsRecompute())->toBeTrue()
        ->and($result->stopReason())->toBeNull()
        ->and(array_map(static fn ($event): string => $event->type->value, $result->events))->toBe(['lap_batch', 'recompute']);
});

it('does not stop at a lap that is behind, which a plan made for another moment may leave: it means nothing', function () {
    $config = RunConfig::constant(75_000, 1_550, 25, 1_500);

    // The plan says lap 5, but the car is at lap 8: it drives on to the end of the tank (30 laps of 45 l) as if there were none.
    $result = (new Settler)->settle(new RunState(0, 8, 45_000, null, 5), 3_600_000, $config);

    expect($result->laps)->toBe(30)
        ->and($result->needsRecompute())->toBeFalse();
});

it('tells the breakdown first when it comes at the same lap, and the car stops for it', function () {
    $config = RunConfig::constant(75_000, 1_550, 25, 1_500);
    $result = (new Settler)->settle(new RunState(0, 0, 45_000, 6, 6), 3_600_000, $config);

    expect(array_map(static fn ($event): EventType => $event->type, $result->events))->toBe([EventType::LapBatch, EventType::Breakdown, EventType::Recompute])
        ->and($result->stopReason())->toBe(EventType::Breakdown);
});

it('settles in passes as it would at once, over thousands of random runs, with the plan at any distance', function () {
    $random = new Randomizer(new Mt19937(20261027));

    for ($case = 0; $case < 2_000; $case++) {
        $weathers = ['dry', 'rain', 'night'];
        $lapMs = $cash = $xp = [];

        foreach ($weathers as $weather) {
            $lapMs[$weather] = $random->getInt(40_000, 200_000);
            $cash[$weather] = $random->getInt(500, 3_000);
            $xp[$weather] = $random->getInt(5, 60);
        }

        $salt = $random->getInt(1, 1_000_000);
        $config = new RunConfig($lapMs, $cash, $xp, $random->getInt(800, 3_000), new CallbackWeather(static fn (int $segment): string => $weathers[(($segment * 2_654_435_761) ^ $salt) % 3]));
        $lapsDone = $random->getInt(0, 20);
        $state = new RunState($random->getInt(0, 5_000_000), $lapsDone, $random->getInt(0, 400_000), $random->getInt(0, 1) === 0 ? null : $lapsDone + $random->getInt(0, 150));
        $now = $state->cursorMs + $random->getInt(0, 20_000_000);
        $every = $random->getInt(1, 40);

        $whole = (new Settler)->settleInChunks($state, $now, $config);
        $passes = settleThroughRecomputes(new RunState($state->cursorMs, $state->lapsDone, $state->fuelMl, $state->breakAfterLaps, $state->lapsDone + $every), $now, $config, $every);

        expect([$passes['laps'], $passes['cash'], $passes['xp'], $passes['state']->cursorMs, $passes['state']->lapsDone, $passes['state']->fuelMl])
            ->toBe([$whole->laps, $whole->cashCents, $whole->xp, $whole->state->cursorMs, $whole->state->lapsDone, $whole->state->fuelMl], "case {$case}, every {$every} laps");
    }
});

it('settles at t1 and then at t2 as it does at t2, with the configuration made again on the way', function () {
    $config = RunConfig::constant(75_000, 1_550, 25, 1_500);
    $state = new RunState(0, 0, 900_000, null, 40);

    $once = settleThroughRecomputes($state, 40_000_000, $config, 40);
    $first = settleThroughRecomputes($state, 10_000_000, $config, 40);
    $second = settleThroughRecomputes($first['state'], 40_000_000, $config, 40);

    expect($first['laps'] + $second['laps'])->toBe($once['laps'])
        ->and($first['cash'] + $second['cash'])->toBe($once['cash'])
        ->and($second['state']->cursorMs)->toBe($once['state']->cursorMs)
        ->and($second['state']->lapsDone)->toBe($once['state']->lapsDone);
});
