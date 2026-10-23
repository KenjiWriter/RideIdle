<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Domain\Market\UsedCarPricing;
use App\Domain\Settlement\Ppm;

function usedCarPricing(): UsedCarPricing
{
    return new UsedCarPricing(Rules::fromFile(dirname(__DIR__, 4).'/content/rules/starter.json')->marketPricing());
}

/** The price must be within a share of what the design gets from its formula (the design rounds, and so does a cent). */
function expectNear(int $cents, int $expected, float $share): void
{
    expect(abs($cents - $expected) / $expected)->toBeLessThan($share);
}

describe('the price of a used car', function () {
    it('gives the three ads of the board of the exchange', function () {
        // Rdzak GT-3 of 14 years, 142 000 km, perfect: $3 620. Kornet V6 of 10 years, 96 000 km, "good": $8 140. Furgon Z-1 of 11 years, 231 000 km, 3 defects: $3 440.
        expectNear(usedCarPricing()->priceCents(1_850_000, 'hatchback', 14, 142_000, Ppm::ONE), 362_000, 0.01);
        expectNear(usedCarPricing()->priceCents(3_000_000, 'coupe', 10, 96_000, 800_000), 814_000, 0.01);
        expectNear(usedCarPricing()->priceCents(2_600_000, 'van', 11, 231_000, 400_000), 344_000, 0.02);
    });

    it('prices the wrecks of the start where the design does', function () {
        // Systemy V: Rdzak GT-4 of 20 years, 260 000 km, condition 30%: $1 140. Bizon M-2, 22 years, 280 000 km, 28%: $1 390. Kuna 4x4, 21 years, 300 000 km, 30%: $1 510.
        expectNear(usedCarPricing()->priceCents(2_150_000, 'hatchback', 20, 260_000, 300_000), 114_000, 0.02);
        expectNear(usedCarPricing()->priceCents(3_400_000, 'muscle', 22, 280_000, 280_000), 139_000, 0.02);
        expectNear(usedCarPricing()->priceCents(3_200_000, 'pickup', 21, 300_000, 300_000), 151_000, 0.02);
    });

    it('is always a whole number of dollars', function () {
        foreach ([[2_150_000, 20, 260_000], [3_000_000, 7, 91_000], [1_550_000, 3, 41_000]] as [$new, $age, $km]) {
            expect(usedCarPricing()->priceCents($new, 'hatchback', $age, $km, 713_000) % 100)->toBe(0);
        }
    });

    it('costs more for a car in a better condition, and less for one that has driven more than its age explains', function () {
        $pricing = usedCarPricing();

        expect($pricing->priceCents(3_000_000, 'coupe', 10, 96_000, 900_000))->toBeGreaterThan($pricing->priceCents(3_000_000, 'coupe', 10, 96_000, 400_000))
            ->and($pricing->priceCents(3_000_000, 'coupe', 10, 150_000, 800_000))->toBeGreaterThan($pricing->priceCents(3_000_000, 'coupe', 10, 280_000, 800_000));
    });

    it('never takes more than 30% off for the mileage, however far a car has driven', function () {
        $pricing = usedCarPricing();

        expect($pricing->priceCents(3_000_000, 'coupe', 5, 5_000_000, Ppm::ONE))->toBe($pricing->priceCents(3_000_000, 'coupe', 5, 380_000, Ppm::ONE));
    });

    it('keeps a collector class from falling to nothing', function () {
        // A hatchback of 25 years is worth the 6% of the floor at best, and a GT the 45%.
        expect(usedCarPricing()->priceCents(10_000_000, 'gt', 25, 10_000, Ppm::ONE))->toBe(4_500_000)
            ->and(usedCarPricing()->priceCents(10_000_000, 'hatchback', 25, 10_000, Ppm::ONE))->toBeLessThan(700_000);
    });

    it('is never less than a dollar', function () {
        expect(usedCarPricing()->priceCents(100, 'hatchback', 25, 280_000, 0))->toBe(100);
    });
});
