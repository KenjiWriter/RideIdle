<?php

declare(strict_types=1);

use App\Domain\Settlement\CallbackWeather;
use App\Domain\Settlement\EventType;
use App\Domain\Settlement\RunConfig;
use App\Domain\Settlement\RunState;
use App\Domain\Settlement\SettlementResult;
use App\Domain\Settlement\Settler;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
| Property tests. Cases come from a seeded generator, so a failure can be reproduced:
| the message names the case number and the seed.
*/

const PROPERTY_SEED = 20261022;
const PROPERTY_CASES = 10_000;

/** @return array{state: RunState, config: RunConfig, t1: int, t2: int} */
function randomCase(Randomizer $random): array
{
    $weathers = ['dry', 'rain', 'night'];
    $lapMs = $cash = $xp = [];

    foreach ($weathers as $weather) {
        $lapMs[$weather] = $random->getInt(40_000, 200_000);
        $cash[$weather] = $random->getInt(500, 3_000);
        $xp[$weather] = $random->getInt(5, 60);
    }

    $salt = $random->getInt(1, 1_000_000);
    $schedule = new CallbackWeather(static fn (int $segment): string => $weathers[(($segment * 2_654_435_761) ^ $salt) % 3]);

    $fuelPerLap = $random->getInt(800, 3_000);
    $lapsDone = $random->getInt(0, 20);
    $cursor = $random->getInt(0, 5_000_000);
    $breakAfter = $random->getInt(0, 1) === 0 ? null : $lapsDone + $random->getInt(0, 150);
    $t1 = $cursor + $random->getInt(0, 20_000_000);

    return [
        'state' => new RunState($cursor, $lapsDone, $random->getInt(0, 400_000), $breakAfter),
        'config' => new RunConfig($lapMs, $cash, $xp, $fuelPerLap, $schedule),
        't1' => $t1,
        't2' => $t1 + $random->getInt(0, 20_000_000),
    ];
}

/** Obviously correct reference: one lap at a time. */
function naiveSettle(RunState $state, int $nowMs, RunConfig $config): array
{
    $cursor = $state->cursorMs;
    $lapsDone = $state->lapsDone;
    $fuel = $state->fuelMl;
    $laps = $cash = $xp = 0;

    while ($fuel >= $config->fuelPerLapMl && ($state->breakAfterLaps === null || $lapsDone < $state->breakAfterLaps)) {
        $weather = $config->weather->at(intdiv($cursor, Settler::SEGMENT_MS));

        if ($cursor + $config->lapMsFor($weather) > $nowMs) {
            break;
        }

        $cursor += $config->lapMsFor($weather);
        $fuel -= $config->fuelPerLapMl;
        $lapsDone++;
        $laps++;
        $cash += $config->lapCashFor($weather);
        $xp += $config->lapXpFor($weather);
    }

    return ['cursor' => $cursor, 'laps_done' => $lapsDone, 'fuel' => $fuel, 'laps' => $laps, 'cash' => $cash, 'xp' => $xp];
}

/** @return array<string, int> */
function summary(SettlementResult $result): array
{
    return [
        'cursor' => $result->state->cursorMs,
        'laps_done' => $result->state->lapsDone,
        'fuel' => $result->state->fuelMl,
        'laps' => $result->laps,
        'cash' => $result->cashCents,
        'xp' => $result->xp,
    ];
}

/** Runs a check for every generated case and reports the case that broke it. */
function forEachCase(int $cases, Closure $check): void
{
    $random = new Randomizer(new Mt19937(PROPERTY_SEED));

    for ($i = 1; $i <= $cases; $i++) {
        $case = randomCase($random);

        try {
            $check($case['state'], $case['config'], $case['t1'], $case['t2']);
        } catch (Throwable $failure) {
            throw new RuntimeException("Case {$i} (seed ".PROPERTY_SEED.') failed: '.$failure->getMessage(), 0, $failure);
        }
    }
}

it('matches a lap by lap simulation', function () {
    $settler = new Settler();

    forEachCase(PROPERTY_CASES, function (RunState $state, RunConfig $config, int $t1) use ($settler) {
        expect(summary($settler->settle($state, $t1, $config)))->toBe(naiveSettle($state, $t1, $config));
    });
});

it('gives the same result when settled in two steps as in one (composition)', function () {
    $settler = new Settler();

    forEachCase(PROPERTY_CASES, function (RunState $state, RunConfig $config, int $t1, int $t2) use ($settler) {
        $first = $settler->settle($state, $t1, $config);
        $second = $settler->settle($first->state, $t2, $config);
        $once = $settler->settle($state, $t2, $config);

        expect($second->state)->toEqual($once->state)
            ->and($first->laps + $second->laps)->toBe($once->laps)
            ->and($first->cashCents + $second->cashCents)->toBe($once->cashCents)
            ->and($first->xp + $second->xp)->toBe($once->xp);

        // A stop is reported exactly once across the two steps, and only when the single step reports it.
        foreach ([EventType::Breakdown, EventType::FuelEmpty] as $type) {
            $count = fn (SettlementResult $result): int => count(array_filter($result->events, fn ($event) => $event->type === $type));

            expect($count($first) + $count($second))->toBe($count($once));
        }
    });
});

it('does nothing the second time at the same moment (idempotence)', function () {
    $settler = new Settler();

    forEachCase(PROPERTY_CASES, function (RunState $state, RunConfig $config, int $t1) use ($settler) {
        $first = $settler->settle($state, $t1, $config);
        $again = $settler->settle($first->state, $t1, $config);

        expect($again->isEmpty())->toBeTrue()
            ->and($again->state)->toEqual($first->state);
    });
});

it('never pays for more laps than the fuel and the breakdown allow, and never goes past now', function () {
    $settler = new Settler();

    forEachCase(PROPERTY_CASES, function (RunState $state, RunConfig $config, int $t1) use ($settler) {
        $result = $settler->settle($state, $t1, $config);

        expect($result->laps)->toBeLessThanOrEqual(Settler::lapBudget($state, $config))
            ->and($result->state->fuelMl)->toBe($state->fuelMl - $result->laps * $config->fuelPerLapMl)
            ->and($result->state->lapsDone)->toBe($state->lapsDone + $result->laps)
            ->and($result->state->cursorMs)->toBeGreaterThanOrEqual($state->cursorMs);

        if ($result->laps > 0) {
            expect($result->state->cursorMs)->toBeLessThanOrEqual($t1);
        }

        if ($state->breakAfterLaps !== null) {
            expect($result->state->lapsDone)->toBeLessThanOrEqual(max($state->lapsDone, $state->breakAfterLaps));
        }
    });
});

it('produces a log that adds up', function () {
    $settler = new Settler();

    forEachCase(PROPERTY_CASES, function (RunState $state, RunConfig $config, int $t1) use ($settler) {
        $result = $settler->settle($state, $t1, $config);
        $batches = array_values(array_filter($result->events, fn ($event) => $event->type === EventType::LapBatch));

        expect(array_sum(array_map(fn ($event) => $event->payload['cash_cents'], $batches)))->toBe($result->cashCents)
            ->and(array_sum(array_map(fn ($event) => $event->payload['xp'], $batches)))->toBe($result->xp)
            ->and(array_sum(array_map(fn ($event) => $event->payload['laps'], $batches)))->toBe($result->laps);

        $expectedFrom = $state->cursorMs;

        foreach ($batches as $batch) {
            $payload = $batch->payload;

            // Batches are contiguous and every lap in them takes the lap time of its weather.
            expect($payload['from_ms'])->toBe($expectedFrom)
                ->and($payload['to_ms'] - $payload['from_ms'])->toBe($payload['laps'] * $config->lapMsFor($payload['weather']))
                ->and($payload['laps'])->toBeGreaterThan(0);

            $expectedFrom = $payload['to_ms'];
        }

        $keys = array_map(fn ($event) => [$event->atMs, $event->priority()], $result->events);
        $sorted = $keys;
        usort($sorted, fn ($a, $b) => $a <=> $b);

        expect($keys)->toBe($sorted);

        $types = array_map(fn ($event) => $event->type, $result->events);

        expect(count(array_keys($types, EventType::Breakdown, true)))->toBeLessThanOrEqual(1)
            ->and(count(array_keys($types, EventType::FuelEmpty, true)))->toBeLessThanOrEqual(1);

        if (in_array(EventType::Breakdown, $types, true)) {
            expect($result->state->lapsDone)->toBe($state->breakAfterLaps);
        }

        if (in_array(EventType::FuelEmpty, $types, true)) {
            expect($result->state->fuelMl)->toBeLessThan($config->fuelPerLapMl);
        }
    });
});

it('earns at least as much when settled later (monotonicity)', function () {
    $settler = new Settler();

    forEachCase(PROPERTY_CASES, function (RunState $state, RunConfig $config, int $t1, int $t2) use ($settler) {
        $early = $settler->settle($state, $t1, $config);
        $late = $settler->settle($state, $t2, $config);

        expect($late->laps)->toBeGreaterThanOrEqual($early->laps)
            ->and($late->cashCents)->toBeGreaterThanOrEqual($early->cashCents);
    });
});

it('settles a long absence in windows without losing or paying anything twice', function (int $windowMs) {
    $settler = new Settler();

    forEachCase(2_000, function (RunState $state, RunConfig $config, int $t1, int $t2) use ($settler, $windowMs) {
        $once = $settler->settle($state, $t2, $config);
        $chunked = $settler->settleInChunks($state, $t2, $config, $windowMs);

        expect($chunked->state)->toEqual($once->state)
            ->and($chunked->laps)->toBe($once->laps)
            ->and($chunked->cashCents)->toBe($once->cashCents)
            ->and($chunked->xp)->toBe($once->xp)
            ->and($chunked->stopReason())->toBe($once->stopReason());
    });
})->with([
    'one hour' => [3_600_000],
    'one segment' => [Settler::SEGMENT_MS],
    'odd 7 minute 13 second window' => [433_000],
    'default 72 hours' => [Settler::MAX_WINDOW_MS],
]);
