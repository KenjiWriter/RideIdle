<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/** What an extra of the simulator is told about a day: where the player is on the ladder, and what the car earns before the extras are counted. */
final readonly class DayState
{
    public function __construct(
        public EconomyRules $rules,
        public PlayerType $player,
        /** The day, from 1. */
        public int $day,
        /** The rung of the car of the player: 0 is the wreck. */
        public int $rung,
        /** The player level after the experience of this day. */
        public int $level,
        /** What the car earns today, in dollars, before the factor of the extras. */
        public float $income,
        /** The cash the player had when the day began, before today's income. */
        public float $cash,
    ) {}
}
