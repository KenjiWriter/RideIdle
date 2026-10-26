<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * The experience it takes in all to reach a level of a driver. It has three stretches, so that the first hour of the game is quick and
 * the long game is the one of the design (Systemy VI):
 *
 * - the first levels are a table: the experience that reaches level 2, 3, and so on, written down, so that every one of them is chosen;
 * - from the end of the table to the catch-up level the curve is a straight line, so that the levels in between get dearer by the same step;
 * - from the catch-up level on it is the curve of the design, base * level ^ exponent.
 *
 * The game and the economy simulator both draw it from here, so that they cannot drift apart.
 */
final class PlayerXpCurve
{
    /**
     * @param  list<int|float>  $early  the experience that reaches level 2, 3, ... (rising)
     * @param  int  $catchUpLevel  the level from which the curve is the plain one (ignored without a table)
     */
    public function __construct(
        private readonly float $base,
        private readonly float $exponent,
        private readonly array $early = [],
        private readonly int $catchUpLevel = 0,
    ) {}

    /** The experience that reaches a level: nothing for the first. */
    public function xpToReach(int $level): float
    {
        if ($level <= 1) {
            return 0.0;
        }

        $tabled = count($this->early);

        if ($level - 2 < $tabled) {
            return (float) $this->early[$level - 2];
        }

        if ($tabled === 0 || $level >= $this->catchUpLevel) {
            return $this->plain($level);
        }

        $from = $tabled + 1;
        $start = (float) $this->early[$tabled - 1];

        return $start + ($this->plain($this->catchUpLevel) - $start) * ($level - $from) / ($this->catchUpLevel - $from);
    }

    /** The highest level, up to `$max`, that this much experience reaches. A new driver is on the first. */
    public function levelAt(float $xp, int $max): int
    {
        $level = 1;

        while ($level < $max && $this->xpToReach($level + 1) <= $xp) {
            $level++;
        }

        return $level;
    }

    private function plain(int $level): float
    {
        return $this->base * $level ** $this->exponent;
    }
}
