<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * What the game lets a driver of a level earn in an hour, set against the anchor of the rung of the ladder that the level belongs to: one row of
 * the game ladder. `car` and `track` are the best pair the game has for that level, `xp` the experience an hour of that pair against the 900 an hour
 * that the model counts (`xpModel`).
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
        public float $xp,
        public float $xpModel,
        public bool $xpOk,
    ) {}

    /** How far the income of the game is from the anchor, as a share of the latter (negative when the game pays less). */
    public function deviation(): float
    {
        return ($this->game - $this->anchor) / $this->anchor;
    }

    /** How far the experience an hour of the game is from the one of the model, as a share of the latter. */
    public function xpDeviation(): float
    {
        return ($this->xp - $this->xpModel) / $this->xpModel;
    }
}
