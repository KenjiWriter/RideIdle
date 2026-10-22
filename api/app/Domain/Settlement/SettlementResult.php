<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/** What one settlement produced: the new state, the ordered log and the totals to credit. */
final readonly class SettlementResult
{
    /** @param list<SettlementEvent> $events ordered by time, then priority */
    public function __construct(
        public RunState $state,
        public array $events,
        public int $laps,
        public int $cashCents,
        public int $xp,
    ) {
    }

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

    public function isEmpty(): bool
    {
        return $this->events === [] && $this->laps === 0;
    }
}
