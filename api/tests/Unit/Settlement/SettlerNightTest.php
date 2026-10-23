<?php

declare(strict_types=1);

use App\Domain\Run\GameClock;
use App\Domain\Settlement\ConstantWeather;
use App\Domain\Settlement\RunConfig;
use App\Domain\Settlement\RunState;
use App\Domain\Settlement\Settler;

/** A lap of 75 s that pays $15.50 and 2 XP, and takes 3% longer at night when the track is not lit. */
function nightConfig(bool $lit): RunConfig
{
    return new RunConfig(['dry' => 75_000], ['dry' => 1_550], ['dry' => 2], 1_500, new ConstantWeather('dry'), $lit ? 1_000_000 : 1_030_000);
}

/** A moment of the game, on a day that is far from the epoch. */
function nightAt(int $hour, int $minute = 0): int
{
    return 400 * GameClock::DAY_MS + $hour * GameClock::HOUR_MS + intdiv($minute * GameClock::HOUR_MS, 60);
}

describe('a track that is not lit at night', function () {
    it('makes a lap that starts at night 3% longer, and a lap that starts by day keeps its time', function () {
        $night = nightAt(23);
        $day = nightAt(12);
        $config = nightConfig(lit: false);

        $atNight = (new Settler)->settle(new RunState($night, 0, 45_000), $night + 77_250 * 4, $config);
        $byDay = (new Settler)->settle(new RunState($day, 0, 45_000), $day + 75_000 * 4, $config);

        expect($atNight->laps)->toBe(4)
            ->and($atNight->state->cursorMs)->toBe($night + 77_250 * 4)
            ->and($byDay->laps)->toBe(4)
            ->and($byDay->state->cursorMs)->toBe($day + 75_000 * 4);
    });

    it('pays no more for a longer lap, so the night costs laps and not the reward of one', function () {
        $night = nightAt(23);

        $result = (new Settler)->settle(new RunState($night, 0, 10_000_000), $night + 2_600_000, nightConfig(lit: false));

        // 2600 s of the night, all of it: 33 laps of 77.25 s, and each is paid the same as by day.
        expect($result->laps)->toBe(33)
            ->and($result->cashCents)->toBe(33 * 1_550)
            ->and($result->xp)->toBe(33 * 2);
    });

    it('does not change a track that is lit, whatever the hour', function () {
        $night = nightAt(23);

        $result = (new Settler)->settle(new RunState($night, 0, 10_000_000), $night + 2_600_000, nightConfig(lit: true));

        expect($result->laps)->toBe(34)
            ->and($result->state->cursorMs)->toBe($night + 34 * 75_000);
    });

    it('cuts the stretch where the night begins: laps before 22:00 take 75 s, and the first one that starts after it 77.25 s', function () {
        $night = nightAt(22);
        $config = nightConfig(lit: false);

        $result = (new Settler)->settle(new RunState($night - 30_000, 0, 45_000), $night + 150_000, $config);

        // The lap that starts 30 s before the night is a day lap and ends at 22:00 + 45 s; the next one starts at night.
        expect($result->laps)->toBe(2)
            ->and($result->state->cursorMs)->toBe($night - 30_000 + 75_000 + 77_250);
    });

    it('cuts the stretch where the night ends, and the first lap that starts at the dawn is a day lap', function () {
        $dawn = nightAt(5);

        $result = (new Settler)->settle(new RunState($dawn - 70_000, 0, 45_000), $dawn + 200_000, nightConfig(lit: false));

        // Starts at night (77.25 s) and ends 7.25 s into the dawn; the next laps start by day (75 s).
        expect($result->state->cursorMs)->toBe($dawn - 70_000 + 77_250 + 75_000 * intdiv($dawn + 200_000 - ($dawn - 70_000 + 77_250), 75_000));
    });

    it('gives the same state and totals when settled in steps as when settled at once, across the whole day', function () {
        $config = nightConfig(lit: false);
        $start = nightAt(0);
        $settler = new Settler;

        // A whole day of the game: every phase, and both edges of the night. Fuel is not a limit.
        $end = $start + GameClock::DAY_MS;
        $once = $settler->settle(new RunState($start, 0, 10_000_000), $end, $config);

        foreach ([7, 13, 29] as $parts) {
            $state = new RunState($start, 0, 10_000_000);
            $laps = 0;
            $cash = 0;
            $xp = 0;

            for ($step = 1; $step <= $parts; $step++) {
                $result = $settler->settle($state, $start + intdiv(($end - $start) * $step, $parts), $config);
                $state = $result->state;
                $laps += $result->laps;
                $cash += $result->cashCents;
                $xp += $result->xp;
            }

            expect($laps)->toBe($once->laps, "{$parts} steps")
                ->and($cash)->toBe($once->cashCents)
                ->and($xp)->toBe($once->xp)
                ->and($state->cursorMs)->toBe($once->state->cursorMs)
                ->and($state->fuelMl)->toBe($once->state->fuelMl);
        }
    });

    it('drives fewer laps in a day than a lit track, by about the share of the night that a lap is longer', function () {
        $start = nightAt(0);
        $end = $start + GameClock::DAY_MS;

        $dark = (new Settler)->settle(new RunState($start, 0, 10_000_000), $end, nightConfig(lit: false));
        $lit = (new Settler)->settle(new RunState($start, 0, 10_000_000), $end, nightConfig(lit: true));

        // Seven of the twenty-four hours are night, and 3% of 7/24 of the laps is about 0.9% of the day.
        expect($dark->laps)->toBeLessThan($lit->laps)
            ->and($lit->laps - $dark->laps)->toBeBetween(1, 12);
    });
});

it('a config without a night is the same all day, and has no darkness', function () {
    $config = RunConfig::constant(75_000, 1_550, 25, 1_500);

    expect($config->hasDarkness())->toBeFalse()
        ->and($config->lapMsAt('dry', nightAt(23)))->toBe(75_000);
});

it('refuses a night that makes the laps shorter', function () {
    expect(fn () => new RunConfig(['dry' => 75_000], ['dry' => 1_550], ['dry' => 2], 1_500, new ConstantWeather('dry'), 990_000))
        ->toThrow(InvalidArgumentException::class);
});
