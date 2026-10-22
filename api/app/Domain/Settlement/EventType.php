<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Kinds of settlement events. At the same moment events are ordered by priority:
 * a lap is paid before the car breaks down, and a breakdown is reported before
 * an empty tank (it is then the reason the car stopped).
 */
enum EventType: string
{
    case LapBatch = 'lap_batch';
    case Breakdown = 'breakdown';
    case FuelEmpty = 'fuel_empty';

    public function priority(): int
    {
        return match ($this) {
            self::LapBatch => 2,
            self::Breakdown => 3,
            self::FuelEmpty => 4,
        };
    }
}
