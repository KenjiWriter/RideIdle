<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use InvalidArgumentException;

/**
 * Whether the tank of a car is filled for the driver when the fuel would run out, and what that costs: the data that the auto-refill gives settlement. The
 * service layer builds it from the wallet when it settles; the frozen configuration of the run knows nothing of it, so a run that was frozen before the
 * feature existed refills like any other.
 *
 * A refill fills the tank. It is free at a moment before `$freeUntilMs` (the pass of the endless refill), and otherwise it takes one of the `$charges` in
 * stock; with neither the car stops for fuel as it always did. `$tankMl` 0 is a driver who does not want it or a game that does not have it.
 */
final readonly class RefillPolicy
{
    public function __construct(public int $tankMl, public int $charges = 0, public int $freeUntilMs = 0)
    {
        if ($tankMl < 0 || $charges < 0 || $freeUntilMs < 0) {
            throw new InvalidArgumentException('The tank, the charges and the end of the pass cannot be negative.');
        }
    }

    /** No tank is filled for the driver. */
    public static function none(): self
    {
        return new self(0);
    }

    /** Whether a refill at this moment is free: the pass is still on. */
    public function isFreeAt(int $atMs): bool
    {
        return $atMs < $this->freeUntilMs;
    }

    /** Whether the tank can be filled at this moment for a car that burns `$fuelPerLapMl` a lap: the tank holds a lap, and a charge or the pass pays for it. */
    public function canRefillAt(int $atMs, int $fuelPerLapMl): bool
    {
        return $this->tankMl >= $fuelPerLapMl && ($this->isFreeAt($atMs) || $this->charges > 0);
    }

    /** The policy after a refill at this moment: a charge less, unless the pass paid for it. */
    public function afterRefill(int $atMs): self
    {
        return $this->isFreeAt($atMs) ? $this : new self($this->tankMl, $this->charges - 1, $this->freeUntilMs);
    }
}
