<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Car;

/** The cars a driver owns. A driver has one for now, so this is the first. */
final class Garage
{
    public function carOf(int $userId): ?Car
    {
        return Car::query()->where('user_id', $userId)->orderBy('id')->first();
    }
}
