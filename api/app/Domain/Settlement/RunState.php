<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use InvalidArgumentException;

/**
 * The part of a run that settlement changes. Everything is an integer.
 *
 * - cursorMs: start of the current (unfinished) lap, in milliseconds since the Unix epoch (UTC).
 * - lapsDone: laps finished since the run started.
 * - fuelMl: fuel in milliliters (watt hours for an electric car).
 * - breakAfterLaps: absolute lap count after which the car breaks down, or null.
 */
final readonly class RunState
{
    public function __construct(
        public int $cursorMs,
        public int $lapsDone,
        public int $fuelMl,
        public ?int $breakAfterLaps = null,
    ) {
        if ($cursorMs < 0 || $lapsDone < 0 || $fuelMl < 0) {
            throw new InvalidArgumentException('Cursor, laps and fuel cannot be negative.');
        }

        if ($breakAfterLaps !== null && $breakAfterLaps < 0) {
            throw new InvalidArgumentException('The breakdown lap cannot be negative.');
        }
    }

    public function with(?int $cursorMs = null, ?int $lapsDone = null, ?int $fuelMl = null): self
    {
        return new self(
            $cursorMs ?? $this->cursorMs,
            $lapsDone ?? $this->lapsDone,
            $fuelMl ?? $this->fuelMl,
            $this->breakAfterLaps,
        );
    }
}
