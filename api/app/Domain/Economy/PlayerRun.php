<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * The way a kind of player went up the ladder of cars: the purchases in order, and the cash and the experience they had
 * left on the last day that was simulated. In the scenario game it also says how many wrecks the player had in the garages by then,
 * and in how many garages of other cities.
 */
final readonly class PlayerRun
{
    /** @param list<Purchase> $purchases the car of rung 1 first */
    public function __construct(
        public PlayerType $player,
        public array $purchases,
        public int $daysSimulated,
        public float $cash,
        public float $xp,
        public int $wrecks = 0,
        public int $extraGarages = 0,
        /** What the extras of the scenario game said about their run, by the name of the extra (`Extra::report`). */
        public array $notes = [],
    ) {}

    /** The day on which the car of this rung (1 is the first after the wreck) was bought, or null when it never was. */
    public function dayOf(int $rung): ?int
    {
        foreach ($this->purchases as $purchase) {
            if ($purchase->rung === $rung) {
                return $purchase->day;
            }
        }

        return null;
    }

    public function purchaseOf(int $rung): ?Purchase
    {
        foreach ($this->purchases as $purchase) {
            if ($purchase->rung === $rung) {
                return $purchase;
            }
        }

        return null;
    }
}
