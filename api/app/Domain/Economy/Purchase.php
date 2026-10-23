<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * A car bought on the way up the ladder: which rung, on which day, and what held the player back until then.
 * `cashReadyDay` and `levelReadyDay` are the days on which the money and the level for this step were there, counted
 * from the day the step began; the later one is what the player waited for.
 */
final readonly class Purchase
{
    public const BINDING_CASH = 'cash';

    public const BINDING_LEVEL = 'level';

    public const BINDING_BOTH = 'both';

    public function __construct(
        public int $rung,
        public int $day,
        public int $cashReadyDay,
        public int $levelReadyDay,
    ) {}

    /** What the player waited for: the money, the level, or neither longer than the other. */
    public function binding(): string
    {
        return match (true) {
            $this->cashReadyDay === $this->levelReadyDay => self::BINDING_BOTH,
            $this->cashReadyDay > $this->levelReadyDay => self::BINDING_CASH,
            default => self::BINDING_LEVEL,
        };
    }
}
