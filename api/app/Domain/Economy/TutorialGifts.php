<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * What the tutorial gives a player in the first quarter of an hour, as the scenario game of the simulator starts with it (`model.tutorial` of the ruleset):
 * the experience of the steps (the laps give the rest of the 4 800 XP of the eighth level), the cash of the steps in dollars, and the garages of other cities
 * that it gives for nothing.
 */
final readonly class TutorialGifts
{
    public function __construct(public float $xp, public float $cash, public int $freeGarages) {}
}
