<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Run\GameClock;

/**
 * Pure settlement of a race run: what happened between the last settled moment
 * and `now`. It reads no clock, no database and no randomness, and uses integers only.
 *
 * Rules (see the settlement specification):
 * - A lap takes the lap time of the weather segment in which it starts and is never split. On a track that is not lit it is longer
 *   when it starts at night, and the stretches of laps are cut at the moments the night begins and ends.
 * - A lap counts when it finishes no later than `now`; the lap in progress is neither paid nor lost
 *   (the cursor stays at its start).
 * - Fuel, and the lap after which the car breaks down, limit the number of laps. The lap after
 *   which the car breaks down is paid. When the breakdown and the empty tank come at the same
 *   moment both are logged, the breakdown first, and it is the reason the car stopped.
 * - Weather changes every 22.5 minutes, counted from the Unix epoch, so laps are counted
 *   per segment with a closed formula instead of one by one.
 *
 * - A part that wears, or a service that falls due, changes the car at a lap that is known when the configuration is frozen. Settlement stops
 *   there with a `recompute` event (it is not a stop of the car), and the caller freezes the configuration again and settles on.
 *
 * Two things come in as data and are not part of the frozen configuration, because they depend on what the driver has bought and when:
 * - A refill policy (the auto-refill): where the fuel would run out and the policy allows it, the tank is filled at that moment (an `auto_refill` event, a charge
 *   used unless the pass pays) and the car drives on. The refill takes no time.
 * - Bonus windows (the VIP account): a lap that finishes inside a window pays more cash and XP, rounded for each lap.
 * Without them the settlement is what it was before they existed.
 *
 * Settling at t1 and then at t2 gives the same state and totals as settling once at t2, provided the policy at t2 is the one that is left of the policy at t1
 * (the charges that the first settlement used are gone).
 */
final class Settler
{
    /** 22.5 minutes in milliseconds. */
    public const SEGMENT_MS = 1_350_000;

    /** The longest window settled in one step by settleInChunks: 72 hours. */
    public const MAX_WINDOW_MS = 259_200_000;

    /** Laps the car can still drive: limited by the fuel, by the planned breakdown, and by the lap at which the configuration has to be made again. */
    public static function lapBudget(RunState $state, RunConfig $config): int
    {
        return self::budget($state->fuelMl, $state->lapsDone, $state->breakAfterLaps, $state->recomputeAfterLaps, $config->fuelPerLapMl);
    }

    private static function budget(int $fuelMl, int $lapsDone, ?int $breakAfterLaps, ?int $recomputeAfterLaps, int $fuelPerLapMl): int
    {
        $budget = intdiv($fuelMl, $fuelPerLapMl);

        if ($breakAfterLaps !== null) {
            $budget = min($budget, max(0, $breakAfterLaps - $lapsDone));
        }

        // A lap that is already behind means nothing: the plan was made for the moment it was frozen, and it is made again when it is reached.
        if ($recomputeAfterLaps !== null && $recomputeAfterLaps > $lapsDone) {
            $budget = min($budget, $recomputeAfterLaps - $lapsDone);
        }

        return $budget;
    }

    /**
     * Settles a long absence in windows of at most $maxWindowMs and merges the results.
     * Because settling in steps equals settling at once, nothing is lost or paid twice;
     * the windows only keep every step (and the transaction around it) small.
     *
     * @param  list<BonusWindow>  $bonus
     */
    public function settleInChunks(RunState $state, int $nowMs, RunConfig $config, int $maxWindowMs = self::MAX_WINDOW_MS, ?RefillPolicy $refill = null, array $bonus = []): SettlementResult
    {
        $refill ??= RefillPolicy::none();
        $events = [];
        $laps = 0;
        $cashCents = 0;
        $xp = 0;
        $bonusCashCents = 0;
        $bonusXp = 0;
        $refills = 0;
        $chargesUsed = 0;
        $windowStart = $state->cursorMs;

        // A car whose tank is empty still goes on where the policy fills it.
        while ($windowStart < $nowMs && (self::lapBudget($state, $config) > 0 || $this->refillsAtOnce($state, $config, $refill))) {
            $windowEnd = min($nowMs, $windowStart + $maxWindowMs);
            $step = $this->settle($state, $windowEnd, $config, $refill, $bonus);

            $state = $step->state;
            $laps += $step->laps;
            $cashCents += $step->cashCents;
            $xp += $step->xp;
            $bonusCashCents += $step->bonusCashCents;
            $bonusXp += $step->bonusXp;
            $refills += $step->refills;
            $chargesUsed += $step->chargesUsed;
            $refill = new RefillPolicy($refill->tankMl, $refill->charges - $step->chargesUsed, $refill->freeUntilMs);
            array_push($events, ...$step->events);

            $windowStart = $windowEnd;
        }

        return new SettlementResult($state, self::ordered($events), $laps, $cashCents, $xp, $bonusCashCents, $bonusXp, $refills, $chargesUsed);
    }

    /**
     * @param  list<BonusWindow>  $bonus
     */
    public function settle(RunState $state, int $nowMs, RunConfig $config, ?RefillPolicy $refill = null, array $bonus = []): SettlementResult
    {
        $refill ??= RefillPolicy::none();
        $cursor = $state->cursorMs;
        $lapsDone = $state->lapsDone;
        $fuelMl = $state->fuelMl;

        $events = [];
        $laps = 0;
        $cashCents = 0;
        $xp = 0;
        $bonusCashCents = 0;
        $bonusXp = 0;
        $refills = 0;
        $chargesUsed = 0;

        do {
            $budget = self::budget($fuelMl, $lapsDone, $state->breakAfterLaps, $state->recomputeAfterLaps, $config->fuelPerLapMl);

            while ($budget > 0 && $cursor < $nowMs) {
                $segment = intdiv($cursor, self::SEGMENT_MS);
                $segmentEnd = ($segment + 1) * self::SEGMENT_MS;

                // A track that is not lit has longer laps at night, so the stretch is cut where the night begins and ends.
                if ($config->hasDarkness()) {
                    $segmentEnd = min($segmentEnd, GameClock::nextNightChangeMs($cursor));
                }

                $weather = $config->weather->at($segment);
                $lapMs = $config->lapMsAt($weather, $cursor);

                // Laps that start in this segment (the last one may end in the next), and laps that end by `now`.
                $startingHere = intdiv($segmentEnd - $cursor + $lapMs - 1, $lapMs);
                $finishingByNow = intdiv($nowMs - $cursor, $lapMs);
                $count = min($startingHere, $finishingByNow, $budget);

                if ($count <= 0) {
                    break; // the next lap is still in progress
                }

                $lapCash = $config->lapCashFor($weather);
                $lapXp = $config->lapXpFor($weather);
                $batchBonusCash = 0;
                $batchBonusXp = 0;

                foreach ($bonus as $window) {
                    $inside = $window->lapsFinishingInside($cursor, $lapMs, $count);

                    $batchBonusCash += $inside * Ppm::mul($lapCash, $window->cashPpm);
                    $batchBonusXp += $inside * Ppm::mul($lapXp, $window->xpPpm);
                }

                $batchCash = $count * $lapCash + $batchBonusCash;
                $batchXp = $count * $lapXp + $batchBonusXp;
                $end = $cursor + $count * $lapMs;

                $events[] = SettlementEvent::lapBatch($segment, $weather, $count, $batchCash, $batchXp, $cursor, $end, $batchBonusCash, $batchBonusXp);

                $cursor = $end;
                $budget -= $count;
                $laps += $count;
                $lapsDone += $count;
                $fuelMl -= $count * $config->fuelPerLapMl;
                $cashCents += $batchCash;
                $xp += $batchXp;
                $bonusCashCents += $batchBonusCash;
                $bonusXp += $batchBonusXp;
            }

            // Where the fuel would run out and the driver has the tank filled for them, it is filled now and the car drives on. (A car that breaks down on the
            // same lap is filled too: it would otherwise wait for the driver with an empty tank as well.)
            $refilled = $fuelMl < $config->fuelPerLapMl && $refill->canRefillAt($cursor, $config->fuelPerLapMl);

            if ($refilled) {
                $free = $refill->isFreeAt($cursor);

                $events[] = SettlementEvent::autoRefill($cursor, $refill->tankMl - $fuelMl, $free);
                $refills++;
                $chargesUsed += $free ? 0 : 1;
                $refill = $refill->afterRefill($cursor);
                $fuelMl = $refill->tankMl;
            }
        } while ($refilled && $cursor < $nowMs);

        // The stop events belong to this settlement only when it drove the car to the stop:
        // settling a car that was already stopped adds nothing.
        if ($laps > 0 && $state->breakAfterLaps !== null && $lapsDone === $state->breakAfterLaps) {
            $events[] = SettlementEvent::breakdown($cursor);
        }

        if ($laps > 0 && $fuelMl < $config->fuelPerLapMl) {
            $events[] = SettlementEvent::fuelEmpty($cursor);
        }

        // Where a part has worn to its next stage or the service has moved on, the stretch ends: the configuration is made again for what follows.
        if ($laps > 0 && $state->recomputeAfterLaps !== null && $lapsDone === $state->recomputeAfterLaps) {
            $events[] = SettlementEvent::recompute($cursor);
        }

        return new SettlementResult(
            $state->with(cursorMs: $cursor, lapsDone: $lapsDone, fuelMl: $fuelMl),
            self::ordered($events),
            $laps,
            $cashCents,
            $xp,
            $bonusCashCents,
            $bonusXp,
            $refills,
            $chargesUsed,
        );
    }

    /** Whether the settlement of this state starts by filling the tank: the car stands in front of an empty tank that the policy fills. */
    private function refillsAtOnce(RunState $state, RunConfig $config, RefillPolicy $refill): bool
    {
        return $state->fuelMl < $config->fuelPerLapMl && $refill->canRefillAt($state->cursorMs, $config->fuelPerLapMl);
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
