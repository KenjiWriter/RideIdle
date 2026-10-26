<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/** What an extra of the simulator does on a day: the cash it spends or brings, and the factor it puts on the income of the car. */
final readonly class DayEffect
{
    public function __construct(
        /** Dollars: negative when the extra spends them, positive when it brings them (what a find was sold for). */
        public float $cash = 0.0,
        /** 1 changes nothing, 1.2 makes the car earn a fifth more today. */
        public float $incomeFactor = 1.0,
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
