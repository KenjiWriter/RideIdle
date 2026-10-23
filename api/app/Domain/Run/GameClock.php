<?php

declare(strict_types=1);

namespace App\Domain\Run;

/**
 * The clock of the game (Systemy II, "Pogoda i pora dnia"): the same for everyone and read from the time of the server alone, so
 * it needs no storage, can be counted backwards by the settlement and forwards by the forecast. Three real hours are one day of the
 * game, so an hour of the game is 7.5 minutes, and the day begins at the start of every third hour of the epoch.
 *
 * The day has four phases: the dawn (5 to 8), the day (8 to 18), the dusk (18 to 22) and the night (22 to 5). Only the night changes
 * the laps (an unlit track takes longer); the rest is for the eye and for the jobs of the cities that come later.
 */
final class GameClock
{
    /** Three real hours: one day of the game, in ms. */
    public const DAY_MS = 10_800_000;

    /** One hour of the game, in ms: 7.5 minutes. */
    public const HOUR_MS = 450_000;

    public const MINUTES_PER_DAY = 1_440;

    public const DAWN = 'dawn';

    public const DAY = 'day';

    public const DUSK = 'dusk';

    public const NIGHT = 'night';

    /** The hour of the game at which every phase begins. The night that begins at 22 lasts until the dawn at 5 the next morning. */
    public const PHASE_STARTS = [5 => self::DAWN, 8 => self::DAY, 18 => self::DUSK, 22 => self::NIGHT];

    /** The hours of the game at which the night begins and ends. */
    private const NIGHT_CHANGES = [5, 22];

    /** The minute of the day of the game, from 0 (midnight) to 1439. */
    public static function minuteOfDay(int $ms): int
    {
        return intdiv(self::offset($ms) * self::MINUTES_PER_DAY, self::DAY_MS);
    }

    public static function phaseAt(int $ms): string
    {
        $hour = intdiv(self::offset($ms), self::HOUR_MS);
        $phase = self::NIGHT;

        foreach (self::PHASE_STARTS as $from => $name) {
            if ($hour >= $from) {
                $phase = $name;
            }
        }

        return $phase;
    }

    public static function isNight(int $ms): bool
    {
        return self::phaseAt($ms) === self::NIGHT;
    }

    /** The first moment after `$ms` at which the phase changes. */
    public static function nextPhaseChangeMs(int $ms): int
    {
        return self::nextAt($ms, array_keys(self::PHASE_STARTS));
    }

    /** The first moment after `$ms` at which the night begins or ends: what splits a stretch of laps that are longer at night. */
    public static function nextNightChangeMs(int $ms): int
    {
        return self::nextAt($ms, self::NIGHT_CHANGES);
    }

    /**
     * The phases as a client needs them to draw the clock.
     *
     * @return list<array{key: string, from_hour: int}>
     */
    public static function phases(): array
    {
        $phases = [];

        foreach (self::PHASE_STARTS as $from => $key) {
            $phases[] = ['key' => $key, 'from_hour' => $from];
        }

        return $phases;
    }

    private static function offset(int $ms): int
    {
        return (($ms % self::DAY_MS) + self::DAY_MS) % self::DAY_MS;
    }

    /**
     * @param  list<int>  $hours  hours of the game, in the order of the day
     */
    private static function nextAt(int $ms, array $hours): int
    {
        $offset = self::offset($ms);
        $dayStart = $ms - $offset;

        foreach ($hours as $hour) {
            if ($hour * self::HOUR_MS > $offset) {
                return $dayStart + $hour * self::HOUR_MS;
            }
        }

        return $dayStart + self::DAY_MS + $hours[0] * self::HOUR_MS;
    }
}
