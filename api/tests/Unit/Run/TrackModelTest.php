<?php

declare(strict_types=1);

use App\Domain\Run\TrackModel;

// The reference car of the first track: 2 600 m at 124.8 km/h is a lap of 75 s, which burns 20 ml for every second (1.5 l), and an hour of it pays $744 and gives 960 XP.

it('makes the lap of the reference car from the length over the speed, and the speed from the lap', function () {
    expect(TrackModel::lapMs(2_600, 1_248_000))->toBe(75_000)
        ->and(TrackModel::speedScaledOf(2_600, 75_000))->toBe(1_248_000)
        // The Autodrome: 3 200 m at 144 km/h, 80 s. The sprint of the scrapyard: 420 m at 126 km/h, 12 s.
        ->and(TrackModel::lapMs(3_200, 1_440_000))->toBe(80_000)
        ->and(TrackModel::lapMs(420, 1_260_000))->toBe(12_000)
        // A lap that has no round speed: 2 900 m in 85 s is 122.8235 km/h to four decimals.
        ->and(TrackModel::lapMs(2_900, 1_228_235))->toBe(85_000);
});

it('makes the fuel of a lap from the load of the engine and the time of the lap', function () {
    expect(TrackModel::fuelMl(20, 75_000))->toBe(1_500)
        ->and(TrackModel::fuelMl(12, 12_000))->toBe(144)
        ->and(TrackModel::fuelMl(32, 300_000))->toBe(9_600)
        // Never nothing: a lap of a few ms still burns a drop.
        ->and(TrackModel::fuelMl(1, 1))->toBe(1);
});

it('makes what a lap pays and gives from what an hour pays and gives, whole numbers', function () {
    expect(TrackModel::cashCents(74_400, 75_000))->toBe(1_550)
        ->and(TrackModel::cashCents(83_368, 95_000))->toBe(2_200)
        ->and(TrackModel::xp(96_000, 75_000))->toBe(20)
        ->and(TrackModel::xp(75_789, 95_000))->toBe(20)
        // The other way round: what an hour is that gives this for such a lap.
        ->and(TrackModel::centsPerHourOf(1_550, 75_000))->toBe(74_400)
        ->and(TrackModel::centiXpPerHourOf(20, 75_000))->toBe(96_000);
});

it('rounds the half upward, and never the other way', function () {
    // 1.5 is 2, 1.499 is 1, 2.5 is 3.
    expect(TrackModel::fuelMl(1, 1_500))->toBe(2)
        ->and(TrackModel::fuelMl(1, 1_499))->toBe(1)
        ->and(TrackModel::fuelMl(1, 2_500))->toBe(3)
        // A lap of 2.5 XP is 3, one of 2.49 is 2.
        ->and(TrackModel::xp(90_000, 10_000))->toBe(3)
        ->and(TrackModel::xp(89_640, 10_000))->toBe(2);
});

it('doubles the lap, the fuel, what a lap pays and what it gives when the length is doubled, and leaves what an hour gives', function () {
    // A small generator of the same numbers on every machine: the lengths, the speeds, and what an hour gives.
    $state = 20_261_030;
    $next = static function (int $below) use (&$state): int {
        $state = ($state * 1_103_515_245 + 12_345) % 2_147_483_648;

        return intdiv($state, 1_024) % $below;
    };

    for ($case = 0; $case < 2_000; $case++) {
        $length = 200 + $next(40_000);
        $speed = (20 + $next(280)) * 10_000 + $next(10_000);
        $load = 12 + $next(21);
        $pay = 20_000 + $next(150_000);
        $xp = 100 * (200 + $next(800));

        $lap = TrackModel::lapMs($length, $speed);
        $twice = TrackModel::lapMs(2 * $length, $speed);

        // A half of a unit is lost on each side of a rounding, so the double lap is within 1 ms of twice the lap, and the fuel and the cash of it within one of twice theirs.
        expect(abs($twice - 2 * $lap))->toBeLessThanOrEqual(1, "case {$case}: lap")
            ->and(abs(TrackModel::fuelMl($load, $twice) - 2 * TrackModel::fuelMl($load, $lap)))->toBeLessThanOrEqual(1, "case {$case}: fuel")
            ->and(abs(TrackModel::cashCents($pay, $twice) - 2 * TrackModel::cashCents($pay, $lap)))->toBeLessThanOrEqual(1, "case {$case}: cash")
            ->and($lap)->toBeGreaterThan(0)
            ->and(TrackModel::fuelMl($load, $lap))->toBeGreaterThan(0);

        // What the laps of an hour pay is what the hour says, to the part of the hour that the last lap does not fill and half a cent for every lap.
        $hourOfLaps = intdiv(3_600_000, $lap);

        expect(abs($hourOfLaps * TrackModel::cashCents($pay, $lap) - $pay))->toBeLessThanOrEqual($pay * $lap / 3_600_000 + $hourOfLaps / 2 + 1, "case {$case}: hour");
    }
});

it('keeps how long a tank lasts the same whatever the length of the track', function () {
    // A tank of 45 l burns for 45 000 ml over 20 ml a second: 2 250 s, to a lap that is not filled and to the rounding of the fuel of a lap.
    foreach ([1_000, 2_600, 9_999, 14_400] as $length) {
        $lap = TrackModel::lapMs($length, 1_248_000);
        $fuel = TrackModel::fuelMl(20, $lap);
        $laps = intdiv(45_000, $fuel);

        expect(abs($laps * $lap - 2_250_000))->toBeLessThanOrEqual($lap + 2_250_000 * 0.5 / $fuel + 1);
    }
});

it('refuses what the arithmetic cannot hold instead of counting it as a float', function () {
    expect(fn () => TrackModel::lapMs(TrackModel::LONGEST_LAP_M + 1, 1_000_000))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TrackModel::lapMs(0, 1_000_000))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TrackModel::lapMs(2_600, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TrackModel::fuelMl(0, 75_000))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TrackModel::cashCents(PHP_INT_MAX, 2))->toThrow(OverflowException::class)
        ->and(fn () => TrackModel::xp(-1, 75_000))->toThrow(InvalidArgumentException::class)
        // The longest lap there is, at the slowest speed that is a speed: 3.6e18 ms is still an integer.
        ->and(TrackModel::lapMs(TrackModel::LONGEST_LAP_M, 1))->toBe(TrackModel::LONGEST_LAP_M * 36_000_000);
});

it('uses no floating point arithmetic', function () {
    $found = [];
    $floats = ['floor', 'ceil', 'round', 'sqrt', 'pow', 'fdiv', 'floatval', 'doubleval', 'log', 'exp', 'number_format'];

    foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 3).'/app/Domain/Run/TrackModel.php')) as $token) {
        if ($token === '/') {
            $found[] = 'the / operator';
        } elseif (is_array($token) && ($token[0] === T_DNUMBER || $token[0] === T_DOUBLE_CAST || $token[0] === T_POW || ($token[0] === T_STRING && in_array(strtolower($token[1]), $floats, true)))) {
            $found[] = "{$token[1]} on line {$token[2]}";
        }
    }

    expect($found)->toBe([]);
});
