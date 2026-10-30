<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a day, a week and a month of the missions start and end: in the time zone of the game, at the hour the rules give, so that a driver who plays in the
 * evening and after midnight has one day of missions and not two. The day goes from the reset hour to the next one, the week from Monday at that hour, and the
 * month from the first at that hour. The windows are worked out in the wall clock of the zone, so that the days when the clocks change are 23 or 25 hours long
 * and nothing slips.
 */
final class MissionPeriods
{
    public function __construct(private readonly Rules $rules) {}

    /**
     * The period of a kind that a moment falls in: its key (the day, the ISO week or the month), and its window in ms, from the start to the start of the next.
     *
     * @return array{key: string, from_ms: int, to_ms: int}
     */
    public function at(string $period, CarbonInterface $now): array
    {
        $settings = $this->rules->missions();
        $zone = $settings['timezone'] ?? 'UTC';
        $hour = $settings['reset_hour'] ?? 0;

        $local = CarbonImmutable::instance($now)->setTimezone($zone);
        $day = $local->setTime($hour, 0);

        // Before the reset hour it is still the day before.
        if ($local < $day) {
            $day = $day->subDay();
        }

        [$start, $end, $key] = match ($period) {
            'daily' => [$day, $day->addDay(), $day->format('Y-m-d')],
            'weekly' => [$monday = $day->startOfWeek(CarbonInterface::MONDAY)->setTime($hour, 0), $monday->addWeek(), $day->format('o-\WW')],
            'monthly' => [$first = $day->startOfMonth()->setTime($hour, 0), $first->addMonthNoOverflow(), $day->format('Y-m')],
        };

        return ['key' => $key, 'from_ms' => $start->getTimestampMs(), 'to_ms' => $end->getTimestampMs()];
    }
}
