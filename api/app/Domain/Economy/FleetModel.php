<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * The fleet that the rules of the game let a driver have, as the scenario game of the simulator plays it (`model.fleet` of the ruleset).
 * Every car drives at once, so a wreck bought into a free place earns what a car of the first rung earns; a garage holds a few cars, and
 * a city that the level of the player opens sells one; and every run that is settled gives the player experience, which is what
 * `otherCarsXpShare` says (1 is what the game does).
 */
final readonly class FleetModel
{
    /** @param  list<int>  $garageCityLevels  the player levels at which the other cities open, each with a garage to buy */
    public function __construct(
        public float $wreckPrice,
        public float $garagePrice,
        public int $garageCapacity,
        public int $homeFreePlaces,
        public array $garageCityLevels,
        public float $otherCarsXpShare,
    ) {}

    /** How many places for wrecks the home garage and this many garages in other cities have. */
    public function places(int $extraGarages): int
    {
        return $this->homeFreePlaces + $this->garageCapacity * $extraGarages;
    }

    /** How many garages in other cities the level of the player lets it have: one for each city that the level opens. */
    public function garagesOpenAt(int $level): int
    {
        return count(array_filter($this->garageCityLevels, static fn (int $opens): bool => $opens <= $level));
    }
}
