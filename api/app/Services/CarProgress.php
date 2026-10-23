<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Models\Car;

/**
 * What a car earns by driving: experience, and the level the experience reaches. The level is what limits the tier of
 * the parts the car can have mounted, so it never goes down, and it stops at the highest level of the rules.
 *
 * The car is locked, so this is called inside the transaction of whatever gave the experience, after the wallet and the
 * run, as everything of the game takes its locks.
 */
final class CarProgress
{
    public function __construct(private readonly Rules $rules) {}

    /** Gives a car experience. Returns the car as it is now, or null when there is nothing to give or no such car. */
    public function award(int $carId, int $xp): ?Car
    {
        if ($xp <= 0) {
            return null;
        }

        $car = Car::query()->whereKey($carId)->lockForUpdate()->first();

        if ($car === null) {
            return null;
        }

        $total = $car->xp + $xp;
        $car->forceFill(['xp' => $total, 'level' => max($car->level, $this->rules->carLevelAt($total))])->save();

        return $car;
    }
}
