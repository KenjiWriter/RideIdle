<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * What one settlement produced: the new state, the ordered log and the totals to credit. `cashCents` and `xp` are what was paid, the bonus of the VIP account
 * included (`bonusCashCents` and `bonusXp` say how much of it that was, so the pay of the track alone is the difference). `refills` is how many times the tank was
 * filled for the driver, and `chargesUsed` how many of those took a charge (the others were free: the pass).
 */
final readonly class SettlementResult
{
    /** @param list<SettlementEvent> $events ordered by time, then priority */
    public function __construct(
        public RunState $state,
        public array $events,
        public int $laps,
        public int $cashCents,
        public int $xp,
        public int $bonusCashCents = 0,
        public int $bonusXp = 0,
        public int $refills = 0,
        public int $chargesUsed = 0,
    ) {}

    /** Why the car stopped during this settlement: a breakdown wins over an empty tank. */
    public function stopReason(): ?EventType
    {
        foreach ($this->events as $event) {
            if ($event->type === EventType::Breakdown) {
                return EventType::Breakdown;
            }
        }

        foreach ($this->events as $event) {
            if ($event->type === EventType::FuelEmpty) {
                return EventType::FuelEmpty;
            }
        }

        return null;
    }

    /** Whether the settlement stopped where the frozen configuration has to be made again: the caller freezes it and settles on. */
    public function needsRecompute(): bool
    {
        foreach ($this->events as $event) {
            if ($event->type === EventType::Recompute) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->events === [] && $this->laps === 0;
    }
}
