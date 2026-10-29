<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use InvalidArgumentException;

/**
 * A stretch of time in which every lap pays more: the VIP account. A lap counts for the window by the moment it finishes (that is when it is paid), and the
 * window is `[$fromMs, $toMs)`. The bonus of a lap is its reward times the ppm, rounded for each lap, so it does not matter how the laps were split into
 * batches or settlements: settling in steps pays what settling at once pays.
 *
 * The bonus is on top of the payout of the track. The economy of the game (the gates, the simulator) is measured without it.
 */
final readonly class BonusWindow
{
    public function __construct(public int $fromMs, public int $toMs, public int $cashPpm, public int $xpPpm)
    {
        if ($fromMs < 0 || $toMs < $fromMs || $cashPpm < 0 || $xpPpm < 0) {
            throw new InvalidArgumentException('A bonus window runs forward and its bonuses cannot be negative.');
        }
    }

    /**
     * How many of the `$count` laps that follow `$startMs`, each `$lapMs` long, finish inside the window (the lap k finishes at `$startMs + k * $lapMs`).
     */
    public function lapsFinishingInside(int $startMs, int $lapMs, int $count): int
    {
        $first = max(1, self::ceilDiv(max(0, $this->fromMs - $startMs), $lapMs));
        $last = min($count, self::ceilDiv(max(0, $this->toMs - $startMs), $lapMs) - 1);

        return max(0, $last - $first + 1);
    }

    private static function ceilDiv(int $amount, int $by): int
    {
        return intdiv($amount, $by) + ($amount % $by > 0 ? 1 : 0);
    }
}
