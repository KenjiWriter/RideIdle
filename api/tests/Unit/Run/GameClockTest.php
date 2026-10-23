<?php

declare(strict_types=1);

use App\Domain\Run\GameClock;

/** A moment of a day of the game that begins at the epoch: the hour and the minute of the game. */
function atGameTime(int $hour, int $minute = 0, int $day = 0): int
{
    return $day * GameClock::DAY_MS + $hour * GameClock::HOUR_MS + intdiv($minute * GameClock::HOUR_MS, 60);
}

it('makes three real hours a day of the game, so that an hour of the game is seven and a half minutes', function () {
    expect(GameClock::DAY_MS)->toBe(3 * 3_600_000)
        ->and(GameClock::HOUR_MS * 24)->toBe(GameClock::DAY_MS)
        ->and(GameClock::HOUR_MS)->toBe(450_000);
});

it('counts the minute of the day of the game from the time alone, the same on every day', function () {
    expect(GameClock::minuteOfDay(0))->toBe(0)
        ->and(GameClock::minuteOfDay(atGameTime(11, 20)))->toBe(11 * 60 + 20)
        ->and(GameClock::minuteOfDay(atGameTime(23, 59)))->toBe(23 * 60 + 59)
        ->and(GameClock::minuteOfDay(GameClock::DAY_MS))->toBe(0)
        ->and(GameClock::minuteOfDay(atGameTime(11, 20, day: 5_000)))->toBe(11 * 60 + 20);
});

it('names the phase of the day: the dawn 5 to 8, the day 8 to 18, the dusk 18 to 22 and the night 22 to 5', function (int $hour, int $minute, string $phase) {
    expect(GameClock::phaseAt(atGameTime($hour, $minute)))->toBe($phase);
})->with([
    'midnight' => [0, 0, 'night'],
    'just before the dawn' => [4, 59, 'night'],
    'the dawn begins' => [5, 0, 'dawn'],
    'just before the day' => [7, 59, 'dawn'],
    'the day begins' => [8, 0, 'day'],
    'noon' => [12, 0, 'day'],
    'just before the dusk' => [17, 59, 'day'],
    'the dusk begins' => [18, 0, 'dusk'],
    'just before the night' => [21, 59, 'dusk'],
    'the night begins' => [22, 0, 'night'],
    'before midnight' => [23, 59, 'night'],
]);

it('says that it is night between 22 and 5 only', function () {
    expect(GameClock::isNight(atGameTime(22)))->toBeTrue()
        ->and(GameClock::isNight(atGameTime(3)))->toBeTrue()
        ->and(GameClock::isNight(atGameTime(5)))->toBeFalse()
        ->and(GameClock::isNight(atGameTime(21, 59)))->toBeFalse();
});

it('finds the next change of the phase after a moment, never that very moment, and goes over to the next day', function () {
    expect(GameClock::nextPhaseChangeMs(atGameTime(0)))->toBe(atGameTime(5))
        ->and(GameClock::nextPhaseChangeMs(atGameTime(5)))->toBe(atGameTime(8))
        ->and(GameClock::nextPhaseChangeMs(atGameTime(12, 30)))->toBe(atGameTime(18))
        ->and(GameClock::nextPhaseChangeMs(atGameTime(21, 59)))->toBe(atGameTime(22))
        ->and(GameClock::nextPhaseChangeMs(atGameTime(22)))->toBe(atGameTime(5, day: 1))
        ->and(GameClock::nextPhaseChangeMs(atGameTime(23, 30, day: 7)))->toBe(atGameTime(5, day: 8));
});

it('finds the next moment at which the night begins or ends', function () {
    expect(GameClock::nextNightChangeMs(atGameTime(0)))->toBe(atGameTime(5))
        ->and(GameClock::nextNightChangeMs(atGameTime(5)))->toBe(atGameTime(22))
        ->and(GameClock::nextNightChangeMs(atGameTime(9)))->toBe(atGameTime(22))
        ->and(GameClock::nextNightChangeMs(atGameTime(22)))->toBe(atGameTime(5, day: 1))
        ->and(GameClock::nextNightChangeMs(atGameTime(23, 59)))->toBe(atGameTime(5, day: 1));
});

it('describes the phases for a client in the order of the day', function () {
    expect(GameClock::phases())->toBe([
        ['key' => 'dawn', 'from_hour' => 5],
        ['key' => 'day', 'from_hour' => 8],
        ['key' => 'dusk', 'from_hour' => 18],
        ['key' => 'night', 'from_hour' => 22],
    ]);
});
