<?php

declare(strict_types=1);

namespace App\Domain\Workshop;

/**
 * Where the jobs of a workshop go in time when several mechanics work at once. Pure and whole numbers (ms), so the times never depend on a machine.
 *
 * A job starts at the first moment at which a mechanic is free for the whole of it, and no earlier than the job before it on the same car ends (one car is
 * never in two jobs at once), and no earlier than the moment it is asked for. With one mechanic that is the end of the last job, which is how the queue of a
 * workshop worked before it had more than one.
 */
final class WorkshopSchedule
{
    /**
     * The first start for a job of `$durationMs`: the moment at which fewer than `$workers` of the `$placed` jobs are at work during the whole of it.
     *
     * @param  list<array{start_ms: int, finish_ms: int}>  $placed  the jobs that keep their times
     * @param  int  $notBeforeMs  now, or the end of the job before it on the car, whichever is later
     */
    public static function startFor(array $placed, int $workers, int $notBeforeMs, int $durationMs): int
    {
        // A mechanic can only get free when a job ends, so these are the only moments worth looking at.
        $candidates = [$notBeforeMs];

        foreach ($placed as $job) {
            if ($job['finish_ms'] > $notBeforeMs) {
                $candidates[] = $job['finish_ms'];
            }
        }

        sort($candidates);

        foreach ($candidates as $start) {
            if (self::busiestMoment($placed, $start, $start + $durationMs) < $workers) {
                return $start;
            }
        }

        // Unreachable: after the last job ends nobody is at work. Kept so that the function always answers.
        return max($candidates);
    }

    /**
     * How many of the jobs are at work at the busiest moment between `$fromMs` and `$toMs`.
     *
     * @param  list<array{start_ms: int, finish_ms: int}>  $placed
     */
    private static function busiestMoment(array $placed, int $fromMs, int $toMs): int
    {
        $overlapping = array_values(array_filter($placed, static fn (array $job): bool => $job['start_ms'] < $toMs && $job['finish_ms'] > $fromMs));

        // The load only grows at the start of a job, so it is enough to look at the moment the window opens and at the start of each job in it.
        $moments = [$fromMs];

        foreach ($overlapping as $job) {
            if ($job['start_ms'] > $fromMs) {
                $moments[] = $job['start_ms'];
            }
        }

        $busiest = 0;

        foreach ($moments as $moment) {
            $busiest = max($busiest, count(array_filter($overlapping, static fn (array $job): bool => $job['start_ms'] <= $moment && $job['finish_ms'] > $moment)));
        }

        return $busiest;
    }
}
