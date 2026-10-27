<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Kinds of settlement events. At the same moment events are ordered by priority:
 * a lap is paid before the car breaks down, and a breakdown is reported before
 * an empty tank (it is then the reason the car stopped). The end of a stretch that needs the configuration again
 * comes after them, and the service is due last (the specification: priority 7).
 */
enum EventType: string
{
    case LapBatch = 'lap_batch';
    case Breakdown = 'breakdown';
    case FuelEmpty = 'fuel_empty';

    /** The frozen configuration has to be made again at this moment: a part wore to its next stage, or the service moved on. Not a stop. */
    case Recompute = 'recompute';

    /** The car has driven as far as its service is due at (the overdue and its penalty come later, in steps). */
    case ServiceDue = 'service_due';

    public function priority(): int
    {
        return match ($this) {
            self::LapBatch => 2,
            self::Breakdown => 3,
            self::FuelEmpty => 4,
            self::Recompute => 5,
            self::ServiceDue => 7,
        };
    }
}
