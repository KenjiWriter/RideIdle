<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * Something a player does with money besides driving, which the scenario game of the simulator plays along with the ladder of cars: the crates
 * of parts, the storage units, the way a car is sold. An extra is made for one run of one player and has its own state (how many crates it
 * has opened), so the simulator is given new ones for every player.
 *
 * Every day, after the experience and the level of the day are known and before anything is bought, the simulator asks each extra what it does
 * that day. It adds up the cash they spend or bring, multiplies the income of the car of the player by their factors, and asks them what they
 * add to the cost of the next step of the ladder.
 */
interface Extra
{
    /** The key under which the report of the extra is put in `PlayerRun::$notes`. */
    public function name(): string;

    /** What the extra does today: the cash it spends (negative) or brings, and the factor of the income of the car (1 is none). */
    public function day(DayState $state): DayEffect;

    /** What the extra adds to the cost of the step from this rung to the next, in dollars (negative when it makes the step cheaper). */
    public function stepCostDelta(EconomyRules $rules, int $rung): float;

    /**
     * What the extra says about its run when it is over: counts, spending and days, for the report. Numbers and strings only, so that it can be
     * printed as JSON.
     *
     * @return array<string, mixed>
     */
    public function report(): array;
}
