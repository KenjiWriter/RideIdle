<?php

declare(strict_types=1);

namespace App\Domain\Market;

use App\Domain\Settlement\Ppm;

/**
 * What a used car is worth (Systemy V, point 16): its price new, times what it keeps of it with the years (never less than the floor of
 * its class), times a factor for the km above what its age explains, times a factor for its condition. Integers only, in cents and ppm,
 * so the same car costs the same on every machine.
 *
 *     price = new * max(floor[class], kept ^ age) * mileage_f * (base + (1 - base) * condition)
 *     mileage_f = max(floor, 1 - max(0, km - per_year * age) / span)
 */
final class UsedCarPricing
{
    /** A car is never given away: this is the least it costs, in cents. */
    private const LEAST_CENTS = 100;

    /** @param  array{age_kept_ppm: int, class_floor_ppm: array<string, int>, mileage_per_year_km: int, mileage_span_km: int, mileage_floor_ppm: int, condition_base_ppm: int}  $pricing */
    public function __construct(private readonly array $pricing) {}

    /**
     * @param  int  $newPriceCents  what the car costs new
     * @param  string  $class  the class of the car (hatchback, sedan...)
     * @param  int  $conditionPpm  from 0 to 1 000 000 (the average condition of its parts)
     * @return int whole dollars, in cents
     */
    public function priceCents(int $newPriceCents, string $class, int $ageYears, int $mileageKm, int $conditionPpm): int
    {
        $kept = Ppm::ONE;

        for ($year = 0; $year < $ageYears; $year++) {
            $kept = Ppm::mul($kept, $this->pricing['age_kept_ppm']);
        }

        $depreciation = max($this->pricing['class_floor_ppm'][$class] ?? 0, $kept);

        $over = max(0, $mileageKm - $this->pricing['mileage_per_year_km'] * $ageYears);
        $mileage = max($this->pricing['mileage_floor_ppm'], Ppm::ONE - intdiv($over * Ppm::ONE, $this->pricing['mileage_span_km']));

        $base = $this->pricing['condition_base_ppm'];
        $condition = max(0, min(Ppm::ONE, $conditionPpm));
        $conditionFactor = $base + intdiv((Ppm::ONE - $base) * $condition, Ppm::ONE);

        $cents = Ppm::mul(Ppm::mul(Ppm::mul($newPriceCents, $depreciation), $mileage), $conditionFactor);

        return max(self::LEAST_CENTS, intdiv($cents + 50, 100) * 100);
    }
}
