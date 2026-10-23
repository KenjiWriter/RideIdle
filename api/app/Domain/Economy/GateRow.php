<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/** One line of the pace gate: the day the design wants a car of a rung bought by a kind of player, and the day it was. */
final readonly class GateRow
{
    public function __construct(
        public string $player,
        public string $rung,
        public int $target,
        public ?int $day,
        public ?string $binding,
        public bool $passed,
    ) {}

    /** How far the simulated day is from the target, as a share of the target, or null when the car was never bought. */
    public function deviation(): ?float
    {
        return $this->day === null ? null : ($this->day - $this->target) / $this->target;
    }
}
