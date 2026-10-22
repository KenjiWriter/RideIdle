<?php

declare(strict_types=1);

use App\Domain\Run\CarStats;
use App\Domain\Run\FrozenRun;
use App\Domain\Run\RunFreezer;
use App\Domain\Run\RunInputs;
use App\Domain\Run\TrackProfile;
use App\Domain\Run\WeatherEffect;
use App\Domain\Settlement\RunState;
use App\Domain\Settlement\Settler;

/**
 * A track where grip and power each count for half, a lap takes 75 s for a car with grip and power 100,
 * and a lap pays $15.50 and 25 XP in dry weather. Rain takes 15% of the grip and 7% of the reward.
 */
function sampleTrack(): TrackProfile
{
    return new TrackProfile(
        weights: ['grip' => 0.5, 'power' => 0.5],
        references: ['grip' => 100.0, 'power' => 100.0],
        baseLapMs: 75_000,
        fuelPerLapMl: 1_500,
        rewardCentsPerLap: 1_550,
        xpPerLap: 25,
    );
}

/** @param  array<string, float>  $stats */
function sampleFreezeInputs(array $stats = ['grip' => 100.0, 'power' => 100.0], float $fuelUse = 1.0, int $breakChance = 0): RunInputs
{
    return new RunInputs(
        car: new CarStats($stats, $fuelUse),
        track: sampleTrack(),
        weatherChancesPpm: ['dry' => 700_000, 'rain' => 300_000],
        weatherEffects: ['rain' => new WeatherEffect(['grip' => 0.85], rewardPpm: 930_000, xpPpm: 930_000)],
        climateSeed: 'region-zlomowo',
        breakChancePerLapPpm: $breakChance,
    );
}

it('gives the base lap time to a car with perf 1', function () {
    $config = (new RunFreezer())->freeze(sampleFreezeInputs(), 'seed', 0)->config;

    expect($config['lap_ms']['dry'])->toBe(75_000)
        ->and($config['lap_cash_cents']['dry'])->toBe(1_550)
        ->and($config['lap_xp']['dry'])->toBe(25)
        ->and($config['fuel_per_lap_ml'])->toBe(1_500);
});

it('slows the car in rain and rounds the reward per lap (vector W5)', function () {
    $config = (new RunFreezer())->freeze(sampleFreezeInputs(), 'seed', 0)->config;

    // perf in rain = 0.5 * 0.85 + 0.5 * 1.0 = 0.925, 75 000 / 0.925 ^ 1.15 = 82 035 ms.
    expect($config['lap_ms']['rain'])->toBe(82_035)
        ->and($config['lap_cash_cents']['rain'])->toBe(1_442)
        ->and($config['lap_xp']['rain'])->toBe(23);
});

it('makes a stronger car faster', function () {
    $config = (new RunFreezer())->freeze(sampleFreezeInputs(['grip' => 150.0, 'power' => 100.0]), 'seed', 0)->config;

    // perf = 0.5 * 1.5 + 0.5 * 1.0 = 1.25.
    expect($config['lap_ms']['dry'])->toBe(58_025);
});

it('limits every statistic between 0.2 and 1.6 of the reference', function (array $stats, int $expectedMs) {
    expect((new RunFreezer())->freeze(sampleFreezeInputs($stats), 'seed', 0)->config['lap_ms']['dry'])->toBe($expectedMs);
})->with([
    'one statistic far above the cap, one at zero' => [['grip' => 1_000.0, 'power' => 0.0], 84_661],
    'both at zero' => [['grip' => 0.0, 'power' => 0.0], 477_394],
    'both far above the cap' => [['grip' => 5_000.0, 'power' => 5_000.0], 43_684],
]);

it('scales the fuel use with the car and never goes below one milliliter', function (float $multiplier, int $expectedMl) {
    $config = (new RunFreezer())->freeze(sampleFreezeInputs(fuelUse: $multiplier), 'seed', 0)->config;

    expect($config['fuel_per_lap_ml'])->toBe($expectedMl);
})->with([
    'thirsty' => [1.25, 1_875],
    'frugal' => [0.8, 1_200],
    'tiny' => [0.00001, 1],
]);

it('freezes the same numbers every time', function () {
    $first = (new RunFreezer())->freeze(sampleFreezeInputs(), 'seed', 3);
    $second = (new RunFreezer())->freeze(sampleFreezeInputs(), 'seed', 3);

    expect($first)->toEqual($second);
});

it('freezes only integers', function () {
    $config = (new RunFreezer())->freeze(sampleFreezeInputs(), 'seed', 0)->config;

    foreach (['lap_ms', 'lap_cash_cents', 'lap_xp'] as $table) {
        foreach ($config[$table] as $value) {
            expect($value)->toBeInt();
        }
    }

    expect($config['fuel_per_lap_ml'])->toBeInt();
});

it('survives being stored as JSON and read back', function () {
    $frozen = (new RunFreezer())->freeze(sampleFreezeInputs(), 'seed', 0);

    $stored = json_decode(json_encode($frozen->config, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    $config = FrozenRun::configFromArray($stored);

    expect($config->lapMsFor('dry'))->toBe(75_000)
        ->and($config->lapMsFor('rain'))->toBe(82_035)
        ->and($config->lapCashFor('rain'))->toBe(1_442)
        ->and($config->fuelPerLapMl)->toBe(1_500)
        ->and($config->weather->at(0))->toBe($frozen->runConfig()->weather->at(0));
});

it('feeds the settlement with the frozen configuration', function () {
    $config = (new RunFreezer())->freeze(sampleFreezeInputs(), 'seed', 0)->runConfig();

    $result = (new Settler())->settle(new RunState(0, 0, 45_000), 3_600_000, $config);

    // The weather comes from the region seed: some laps are in rain, so the lap count is below the
    // 30 laps the fuel allows only when rain slowed the car enough; either way every lap is paid its own weather.
    expect($result->laps)->toBeGreaterThan(0)
        ->and($result->laps)->toBeLessThanOrEqual(30)
        ->and($result->state->fuelMl)->toBe(45_000 - $result->laps * 1_500);

    $expected = 0;

    foreach ($result->events as $event) {
        if (isset($event->payload['weather'])) {
            $expected += $event->payload['laps'] * $config->lapCashFor($event->payload['weather']);
        }
    }

    expect($result->cashCents)->toBe($expected);
});

it('plans no breakdown when the car is in perfect condition', function () {
    expect((new RunFreezer())->freeze(sampleFreezeInputs(breakChance: 0), 'seed', 0)->breakAfterLaps)->toBeNull();
});

it('plans a breakdown after a later lap, the same one for the same seed', function () {
    $inputs = sampleFreezeInputs(breakChance: 10_000);

    $first = (new RunFreezer())->freeze($inputs, 'seed-a', 7)->breakAfterLaps;
    $again = (new RunFreezer())->freeze($inputs, 'seed-a', 7)->breakAfterLaps;

    expect($first)->toBeInt()->toBeGreaterThan(7)
        ->and($again)->toBe($first);
});

it('plans different breakdowns for different seeds, on average after 1 / chance laps', function () {
    $inputs = sampleFreezeInputs(breakChance: 10_000);
    $laps = [];

    for ($i = 0; $i < 3_000; $i++) {
        $laps[] = (new RunFreezer())->freeze($inputs, "seed-{$i}", 0)->breakAfterLaps;
    }

    $mean = array_sum($laps) / count($laps);

    // A 1% chance per lap means about 100 laps; with 3 000 seeds the mean is within 8%.
    expect(count(array_unique($laps)))->toBeGreaterThan(50)
        ->and($mean)->toBeGreaterThan(92)->and($mean)->toBeLessThan(108);
});

it('breaks down after the very next lap when the chance is certain', function () {
    expect((new RunFreezer())->freeze(sampleFreezeInputs(breakChance: 1_000_000), 'seed', 12)->breakAfterLaps)->toBe(13);
});

it('rejects inputs that cannot be frozen', function (Closure $build) {
    $build();
})->throws(InvalidArgumentException::class)->with([
    'weights do not add up to one' => [fn () => new TrackProfile(['grip' => 0.6, 'power' => 0.6], ['grip' => 100.0, 'power' => 100.0], 75_000, 1_500, 1_550, 25)],
    'missing reference' => [fn () => new TrackProfile(['grip' => 1.0], [], 75_000, 1_500, 1_550, 25)],
    'zero base lap time' => [fn () => new TrackProfile(['grip' => 1.0], ['grip' => 100.0], 0, 1_500, 1_550, 25)],
    'negative statistic' => [fn () => new CarStats(['grip' => -1.0])],
    'zero fuel use multiplier' => [fn () => new CarStats(['grip' => 1.0], 0.0)],
    'weather chances do not add up' => [fn () => new RunInputs(new CarStats(['grip' => 1.0]), sampleTrack(), ['dry' => 500_000], [], 'seed')],
    'empty climate seed' => [fn () => new RunInputs(new CarStats(['grip' => 1.0]), sampleTrack(), ['dry' => 1_000_000], [], '')],
    'breakdown chance above one' => [fn () => new RunInputs(new CarStats(['grip' => 1.0]), sampleTrack(), ['dry' => 1_000_000], [], 'seed', 1_000_001)],
    'weather multiplier of zero' => [fn () => new WeatherEffect(['grip' => 0.0])],
]);

it('refuses to freeze a car without a statistic the track needs', function () {
    (new RunFreezer())->freeze(sampleFreezeInputs(['grip' => 100.0]), 'seed', 0);
})->throws(InvalidArgumentException::class);

it('refuses to rebuild a configuration that is incomplete', function () {
    FrozenRun::configFromArray(['lap_ms' => ['dry' => 75_000]]);
})->throws(InvalidArgumentException::class);
