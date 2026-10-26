<?php

declare(strict_types=1);

use App\Domain\Economy\PlayerXpCurve;

function theCurve(): PlayerXpCurve
{
    return new PlayerXpCurve(450, 2.3, [60, 180, 400, 800, 1_600, 2_800, 4_800, 8_000, 12_800], 20);
}

it('takes nothing to be on the first level, and the written numbers for the levels of the table', function () {
    expect(theCurve()->xpToReach(1))->toBe(0.0)
        ->and(theCurve()->xpToReach(0))->toBe(0.0)
        ->and(theCurve()->xpToReach(2))->toBe(60.0)
        ->and(theCurve()->xpToReach(5))->toBe(800.0)
        ->and(theCurve()->xpToReach(10))->toBe(12_800.0);
});

it('goes in a straight line from the end of the table to the curve at the catch-up level', function () {
    $curve = theCurve();
    $step = ($curve->xpToReach(20) - $curve->xpToReach(10)) / 10;

    foreach (range(11, 19) as $level) {
        expect($curve->xpToReach($level))->toEqualWithDelta(12_800 + $step * ($level - 10), 0.001);
    }
});

it('is the plain curve from the catch-up level on', function () {
    expect(theCurve()->xpToReach(20))->toEqualWithDelta(450 * 20 ** 2.3, 0.001)
        ->and(theCurve()->xpToReach(30))->toEqualWithDelta(450 * 30 ** 2.3, 0.001);
});

it('is the plain curve all the way when there is no table', function () {
    $plain = new PlayerXpCurve(45, 2.3);

    expect($plain->xpToReach(2))->toEqualWithDelta(45 * 2 ** 2.3, 0.001)
        ->and($plain->xpToReach(9))->toEqualWithDelta(45 * 9 ** 2.3, 0.001);
});

it('never lets a level cost less than the one before it', function () {
    $previous = 0.0;

    foreach (range(1, 30) as $level) {
        expect(theCurve()->xpToReach($level))->toBeGreaterThanOrEqual($previous);
        $previous = theCurve()->xpToReach($level);
    }
});

it('finds the level that this much experience reaches, never above the highest', function () {
    $curve = theCurve();

    expect($curve->levelAt(0, 30))->toBe(1)
        ->and($curve->levelAt(59.9, 30))->toBe(1)
        ->and($curve->levelAt(60, 30))->toBe(2)
        ->and($curve->levelAt(12_800, 30))->toBe(10)
        ->and($curve->levelAt(PHP_INT_MAX, 30))->toBe(30)
        ->and($curve->levelAt(PHP_INT_MAX, 12))->toBe(12);
});
