<?php

declare(strict_types=1);

namespace App\Domain\Run;

use InvalidArgumentException;
use OverflowException;

/**
 * What a track gives the car it is made for, from its length and its speed: the lap, the fuel, the pay and the experience of it. The length is the source: a lap
 * is the length over the speed, the fuel is what the engine burns in that time, and the pay and the experience are what an hour of the track gives, for the time
 * the lap takes. Doubling the length doubles all four numbers of a lap and leaves what an hour gives, and how long a tank lasts, as they were.
 *
 * Integers only. The speed comes in ten-thousandths of a km/h (the content layer makes it from the decimal number of the document), every result is rounded with
 * the half going up, and a length or a speed that would overflow an integer is refused instead of being counted as a float: a result that depends on the
 * machine is worse than no result.
 */
final class TrackModel
{
    /** The speed is counted in this many parts of a km/h. */
    public const SPEED_SCALE = 10_000;

    /** The longest lap the arithmetic holds: 10^11 m (about two thirds of an astronomical unit). */
    public const LONGEST_LAP_M = 100_000_000_000;

    /** A lap shorter than this is no lap of a track: it is not worth a driver's wait, and the experience of it cannot be rounded to anything fair. */
    public const MIN_LAP_MS = 8_000;

    /** A track shorter than this is a stunt. */
    public const MIN_LENGTH_M = 200;

    /** The slowest and the quickest the reference car of a track can be, in km/h. */
    public const MIN_SPEED_KMH = 20;

    public const MAX_SPEED_KMH = 300;

    /** How long an hour is, in ms. */
    private const HOUR_MS = 3_600_000;

    /** m over km/h in ms: a metre at one km/h takes 3 600 ms, and the speed has `SPEED_SCALE` parts in a km/h. */
    private const LAP_FACTOR = 3_600 * self::SPEED_SCALE;

    /** The lap of the reference car, in ms: the length over the speed. */
    public static function lapMs(int $lengthM, int $speedScaled): int
    {
        if ($lengthM < 1 || $lengthM > self::LONGEST_LAP_M) {
            throw new InvalidArgumentException('The length of a lap must be between 1 m and '.self::LONGEST_LAP_M.' m.');
        }

        if ($speedScaled < 1) {
            throw new InvalidArgumentException('The speed of a track must be positive.');
        }

        return max(1, self::half(self::product($lengthM, self::LAP_FACTOR), $speedScaled));
    }

    /** The speed that gives this lap over this length, in ten-thousandths of a km/h (the other way round of `lapMs`, rounded). */
    public static function speedScaledOf(int $lengthM, int $lapMs): int
    {
        if ($lengthM < 1 || $lengthM > self::LONGEST_LAP_M || $lapMs < 1) {
            throw new InvalidArgumentException('A lap needs a length and a time.');
        }

        return max(1, self::half(self::product($lengthM, self::LAP_FACTOR), $lapMs));
    }

    /** The fuel of a lap for a car of the fuel use of the track (1.0), in ml: what the engine burns every second of the time of the lap, at the load of the kind of track. */
    public static function fuelMl(int $loadMlPerSecond, int $lapMs): int
    {
        if ($loadMlPerSecond < 1) {
            throw new InvalidArgumentException('The load of a track must be positive.');
        }

        return max(1, self::half(self::product($loadMlPerSecond, $lapMs), 1_000));
    }

    /** What a lap of the reference car pays in dry weather, in cents: what an hour pays for the time the lap takes. */
    public static function cashCents(int $centsPerHour, int $lapMs): int
    {
        return self::half(self::product($centsPerHour, $lapMs), self::HOUR_MS);
    }

    /** The experience of a lap in dry weather: what an hour gives (in hundredths of a point) for the time the lap takes, a whole number. */
    public static function xp(int $centiXpPerHour, int $lapMs): int
    {
        return self::half(self::product($centiXpPerHour, $lapMs), self::HOUR_MS * 100);
    }

    /** The pay of an hour that gives this much for a lap of this time, in cents (the other way round of `cashCents`, rounded). */
    public static function centsPerHourOf(int $lapCents, int $lapMs): int
    {
        return self::half(self::product($lapCents, self::HOUR_MS), max(1, $lapMs));
    }

    /** The experience of an hour that gives this much for a lap of this time, in hundredths of a point (the other way round of `xp`, rounded). */
    public static function centiXpPerHourOf(int $lapXp, int $lapMs): int
    {
        return self::half(self::product($lapXp * 100, self::HOUR_MS), max(1, $lapMs));
    }

    /** The product of two whole numbers that are not negative, refused when it is more than the integers hold (it would turn into a float without a word). */
    private static function product(int $a, int $b): int
    {
        if ($a < 0 || $b < 0) {
            throw new InvalidArgumentException('A track has no negative numbers.');
        }

        if ($a !== 0 && $b > intdiv(PHP_INT_MAX, $a)) {
            throw new OverflowException('The numbers of the track are too big for the integers of the game.');
        }

        return $a * $b;
    }

    /** A positive quotient rounded to the nearest whole, the half going up, without a number that is more than the integers hold. */
    private static function half(int $numerator, int $denominator): int
    {
        $whole = intdiv($numerator, $denominator);
        $rest = $numerator - $whole * $denominator;

        return $rest >= $denominator - $rest ? $whole + 1 : $whole;
    }
}
