<?php

declare(strict_types=1);

use App\Domain\Run\Rng;
use App\Domain\Run\SeededWeather;

it('rolls the same number for the same seed, domain and index (known values)', function () {
    // Computed with sha256 over "seed|domain|index": the first 32 bits modulo one million.
    expect(Rng::roll('abc', 'break', 1))->toBe(529_645)
        ->and(Rng::roll('abc', 'weather', 0))->toBe(579_880)
        ->and(Rng::roll('abc', 'weather', 1))->toBe(430_898);
});

it('rolls numbers below one million that differ by seed, domain and index', function () {
    $rolls = [];

    for ($i = 0; $i < 500; $i++) {
        $roll = Rng::roll('seed', 'weather', $i);
        expect($roll)->toBeGreaterThanOrEqual(0)->and($roll)->toBeLessThan(1_000_000);
        $rolls[] = $roll;
    }

    expect(count(array_unique($rolls)))->toBeGreaterThan(490)
        ->and(Rng::roll('seed', 'weather', 1))->not->toBe(Rng::roll('other', 'weather', 1))
        ->and(Rng::roll('seed', 'weather', 1))->not->toBe(Rng::roll('seed', 'break', 1));
});

it('spreads rolls evenly', function () {
    $below = 0;

    for ($i = 0; $i < 20_000; $i++) {
        if (Rng::roll('even', 'test', $i) < 500_000) {
            $below++;
        }
    }

    expect($below)->toBeGreaterThan(9_700)->and($below)->toBeLessThan(10_300);
});

it('gives the same weather for the same segment every time', function () {
    $weather = new SeededWeather('region', ['dry' => 700_000, 'rain' => 300_000]);

    expect($weather->at(1_327_000))->toBe($weather->at(1_327_000))
        ->and((new SeededWeather('region', ['dry' => 700_000, 'rain' => 300_000]))->at(42))->toBe($weather->at(42));
});

it('rains about as often as the climate says', function () {
    $weather = new SeededWeather('region', ['dry' => 700_000, 'rain' => 300_000]);
    $rain = 0;

    for ($segment = 0; $segment < 20_000; $segment++) {
        if ($weather->at($segment) === 'rain') {
            $rain++;
        }
    }

    expect($rain)->toBeGreaterThan(5_700)->and($rain)->toBeLessThan(6_300);
});

it('gives different regions different weather', function () {
    $a = new SeededWeather('region-a', ['dry' => 500_000, 'rain' => 500_000]);
    $b = new SeededWeather('region-b', ['dry' => 500_000, 'rain' => 500_000]);
    $different = 0;

    for ($segment = 0; $segment < 200; $segment++) {
        if ($a->at($segment) !== $b->at($segment)) {
            $different++;
        }
    }

    expect($different)->toBeGreaterThan(50);
});

it('always returns the only possible weather', function () {
    $weather = new SeededWeather('region', ['dry' => 0, 'rain' => 1_000_000]);

    foreach ([0, 1, 99, 1_327_000] as $segment) {
        expect($weather->at($segment))->toBe('rain');
    }
});

it('rejects chances that do not add up or are negative', function (array $chances) {
    new SeededWeather('region', $chances);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [[]],
    'too low' => [['dry' => 500_000]],
    'too high' => [['dry' => 800_000, 'rain' => 800_000]],
    'negative' => [['dry' => 1_200_000, 'rain' => -200_000]],
]);
