<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Pure settlement of a race run: what happened between the last settled moment
 * and `now`. It reads no clock, no database and no randomness, and uses integers only.
 *
 * Rules (see the settlement specification):
 * - A lap takes the lap time of the weather segment in which it starts and is never split.
 * - A lap counts when it finishes no later than `now`; the lap in progress is neither paid nor lost
 *   (the cursor stays at its start).
 * - Fuel, and the lap after which the car breaks down, limit the number of laps. The lap after
 *   which the car breaks down is paid. When the breakdown and the empty tank come at the same
 *   moment both are logged, the breakdown first, and it is the reason the car stopped.
 * - Weather changes every 22.5 minutes, counted from the Unix epoch, so laps are counted
 *   per segment with a closed formula instead of one by one.
 *
 * Settling at t1 and then at t2 gives the same state and totals as settling once at t2.
 */
final class Settler
{
    /** 22.5 minutes in milliseconds. */
    public const SEGMENT_MS = 1_350_000;

    public function settle(RunState $state, int $nowMs, RunConfig $config): SettlementResult
    {
        $cursor = $state->cursorMs;

        // Laps the car can still drive: limited by the fuel and by the planned breakdown.
        $budget = intdiv($state->fuelMl, $config->fuelPerLapMl);

        if ($state->breakAfterLaps !== null) {
            $budget = min($budget, max(0, $state->breakAfterLaps - $state->lapsDone));
        }

        $events = [];
        $laps = 0;
        $cashCents = 0;
        $xp = 0;

        while ($budget > 0 && $cursor < $nowMs) {
            $segment = intdiv($cursor, self::SEGMENT_MS);
            $segmentEnd = ($segment + 1) * self::SEGMENT_MS;

            $weather = $config->weather->at($segment);
            $lapMs = $config->lapMsFor($weather);

            // Laps that start in this segment (the last one may end in the next), and laps that end by `now`.
            $startingHere = intdiv($segmentEnd - $cursor + $lapMs - 1, $lapMs);
            $finishingByNow = intdiv($nowMs - $cursor, $lapMs);
            $count = min($startingHere, $finishingByNow, $budget);

            if ($count <= 0) {
                break; // the next lap is still in progress
            }

            $batchCash = $count * $config->lapCashFor($weather);
            $batchXp = $count * $config->lapXpFor($weather);
            $end = $cursor + $count * $lapMs;

            $events[] = SettlementEvent::lapBatch($segment, $weather, $count, $batchCash, $batchXp, $cursor, $end);

            $cursor = $end;
            $budget -= $count;
            $laps += $count;
            $cashCents += $batchCash;
            $xp += $batchXp;
        }

        $lapsDone = $state->lapsDone + $laps;
        $fuelMl = $state->fuelMl - $laps * $config->fuelPerLapMl;

        // The stop events belong to this settlement only when it drove the car to the stop:
        // settling a car that was already stopped adds nothing.
        if ($laps > 0 && $state->breakAfterLaps !== null && $lapsDone === $state->breakAfterLaps) {
            $events[] = SettlementEvent::breakdown($cursor);
        }

        if ($laps > 0 && $fuelMl < $config->fuelPerLapMl) {
            $events[] = SettlementEvent::fuelEmpty($cursor);
        }

        return new SettlementResult(
            $state->with(cursorMs: $cursor, lapsDone: $lapsDone, fuelMl: $fuelMl),
            self::ordered($events),
            $laps,
            $cashCents,
            $xp,
        );
    }

    /**
     * Orders events by time and then by priority; events that are equal on both
     * keep the order in which they were produced.
     *
     * @param  list<SettlementEvent>  $events
     * @return list<SettlementEvent>
     */
    private static function ordered(array $events): array
    {
        usort($events, static fn (SettlementEvent $a, SettlementEvent $b): int => [$a->atMs, $a->priority()] <=> [$b->atMs, $b->priority()]);

        return $events;
    }
}
