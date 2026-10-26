<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * What the game lets a driver of a level earn in an hour, set against the anchor of the rung of the ladder that the level belongs to: one row of
 * the game ladder. `car` and `track` are the best pair the game has for that level.
 */
final readonly class LadderRow
{
    public function __construct(
        public int $level,
        public string $rung,
        public float $anchor,
        public float $game,
        public string $car,
        public string $track,
        public bool $ok,
    ) {}

    /** How far the income of the game is from the anchor, as a share of the latter (negative when the game pays less). */
    public function deviation(): float
    {
        return ($this->game - $this->anchor) / $this->anchor;
    }
}
