<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Run\Rng;
use App\Models\Car;
use App\Models\Part;

/**
 * What driving does to a car: the odometer grows, and the parts that sit on it wear. The wear of a lap (and of a kilometre) is a whole number that was
 * frozen with the configuration of the run, so the wear of n laps is exactly n times it, and adding it in pieces gives what adding it at once does.
 */
final class CarWear
{
    /** A part that is as good as new still breaks down now and then: this much wear is added to what it has when the part that broke down is drawn. */
    private const BASE_WEIGHT_PPB = 100_000_000;

    /**
     * The car has driven `$laps` laps of the frozen configuration: its odometer and its parts are put forward by them.
     *
     * @param  array<string, mixed>  $config  the frozen configuration of the run (its `wear` has the metres of a lap and the wear of a lap by slot)
     */
    public function apply(int $carId, array $config, int $laps): void
    {
        $wear = $config['wear'] ?? null;

        if ($wear === null || $laps <= 0) {
            return;
        }

        Car::query()->whereKey($carId)->increment('mileage_m', $laps * $wear['length_m']);

        foreach ($wear['ppb_per_lap'] as $slot => $perLap) {
            $this->wearSlot($carId, (string) $slot, $laps * (int) $perLap);
        }
    }

    /**
     * The car has been driven `$km` kilometres on the road (a journey): the same, by the kilometre.
     *
     * @param  array<string, mixed>  $config
     */
    public function applyDistance(int $carId, array $config, int $km): void
    {
        $wear = $config['wear'] ?? null;

        if ($wear === null || $km <= 0) {
            return;
        }

        Car::query()->whereKey($carId)->increment('mileage_m', $km * 1000);

        foreach ($wear['ppb_per_km'] as $slot => $perKm) {
            $this->wearSlot($carId, (string) $slot, $km * (int) $perKm);
        }
    }

    /**
     * The part of the car that broke down, drawn from the seed of the run and the lap: the more worn a part is, the likelier it is. Null for a car
     * that has no part that wears.
     */
    public function breakdownPart(int $carId, string $seed, int $lap): ?string
    {
        $parts = Part::query()->where('car_id', $carId)->whereNotNull('mounted_slot')->orderBy('mounted_slot')->get(['mounted_slot', 'wear_ppb']);

        if ($parts->isEmpty()) {
            return null;
        }

        $weights = $parts->map(static fn (Part $part): int => self::BASE_WEIGHT_PPB + (int) $part->wear_ppb)->all();
        $target = intdiv(Rng::roll($seed, 'part', $lap) * array_sum($weights), 1_000_000);
        $reached = 0;

        foreach ($parts as $index => $part) {
            $reached += $weights[$index];

            if ($target < $reached) {
                return $part->mounted_slot;
            }
        }

        return $parts->last()->mounted_slot;
    }

    private function wearSlot(int $carId, string $slot, int $ppb): void
    {
        if ($ppb > 0) {
            Part::query()->where('car_id', $carId)->where('mounted_slot', $slot)->increment('wear_ppb', $ppb);
        }
    }
}
