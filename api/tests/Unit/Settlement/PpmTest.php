<?php

declare(strict_types=1);

use App\Domain\Settlement\Ppm;

it('rounds half up for rewards (vector W5)', function () {
    // Rain multiplies the reward by 0.93: 1 550 cents become 1 442 and 25 XP become 23 per lap.
    expect(Ppm::mul(1550, 930_000))->toBe(1442)
        ->and(Ppm::mul(25, 930_000))->toBe(23);
});

it('rounds the frozen per-lap value, not the total', function () {
    $perLap = Ppm::mul(1550, 930_000);

    // 14 laps: 20 188 cents from the frozen value, not 20 181 from rounding the product at the end.
    expect(14 * $perLap)->toBe(20_188)
        ->and(Ppm::mul(14 * 1550, 930_000))->toBe(20_181);
});

it('rounds exact halves away from zero', function (int $amount, int $ppm, int $expected) {
    expect(Ppm::mul($amount, $ppm))->toBe($expected);
})->with([
    'half rounds up' => [1, 500_000, 1],
    'one and a half rounds up' => [3, 500_000, 2],
    'negative half rounds away from zero' => [-1, 500_000, -1],
    'negative one and a half' => [-3, 500_000, -2],
    'below half rounds down' => [1, 499_999, 0],
    'identity' => [12_345, 1_000_000, 12_345],
    'doubling' => [12_345, 2_000_000, 24_690],
    'zero amount' => [0, 930_000, 0],
    'zero factor' => [1550, 0, 0],
    'negative factor' => [100, -500_000, -50],
]);

it('refuses amounts that would overflow', function () {
    Ppm::mul(PHP_INT_MAX, 2_000_000);
})->throws(OverflowException::class);
