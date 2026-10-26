<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Domain\Market\StorageOutcome;
use App\Domain\Market\StorageUnits;
use App\Domain\Market\UsedCarPricing;

function storageRules(): Rules
{
    return Rules::fromFile(dirname(__DIR__, 4).'/content/rules/starter.json');
}

function storageUnits(): StorageUnits
{
    return new StorageUnits(storageRules()->marketStorage(), new UsedCarPricing(storageRules()->marketPricing()));
}

/**
 * The cars of the pack that ask a level or less of a driver: the ones that can be in a unit that asks for it.
 *
 * @return list<array{slug: string, class: string, price_cents: int, mass_kg: int}>
 */
function storagePool(int $level): array
{
    $pool = [];

    foreach (glob(dirname(__DIR__, 4).'/content/packs/core/vehicles/*.json') as $file) {
        $vehicle = json_decode((string) file_get_contents($file), true);

        if ($vehicle['powertrain'] === 'ice' && $vehicle['min_player_level'] <= $level) {
            $pool[] = ['slug' => $vehicle['slug'], 'class' => $vehicle['class'], 'price_cents' => $vehicle['price_cents'], 'mass_kg' => $vehicle['mass_kg']];
        }
    }

    usort($pool, fn (array $a, array $b): int => $a['slug'] <=> $b['slug']);

    return $pool;
}

/** What a driver is paid for the car that was in a unit, if they sell it at once. */
function storageSale(StorageOutcome $outcome, array $pool): int
{
    $mass = collect($pool)->firstWhere('slug', $outcome->vehicle)['mass_kg'];

    return max($mass * storageRules()->marketScrapCentsPerKg(), (int) round($outcome->valueCents * storageRules()->marketResalePpm() / 1_000_000));
}

describe('the storage units', function () {
    it('give the same car for the same seed, and other cars for other seeds', function () {
        $pool = storagePool(3);

        expect(storageUnits()->open('seed-1', 'small', $pool, 0))->toEqual(storageUnits()->open('seed-1', 'small', $pool, 0));

        $slugs = [];

        foreach (range(1, 40) as $n) {
            $slugs[] = storageUnits()->open("seed-{$n}", 'small', $pool, 0)->vehicle.storageUnits()->open("seed-{$n}", 'small', $pool, 0)->ageYears;
        }

        expect(count(array_unique($slugs)))->toBeGreaterThan(10);
    });

    it('only hold the cars of the pool they are given', function () {
        $pool = storagePool(3);
        $slugs = array_column($pool, 'slug');

        foreach (range(1, 300) as $n) {
            expect($slugs)->toContain(storageUnits()->open("pool-{$n}", 'small', $pool, 0)->vehicle);
        }
    });

    it('keep to the age, the mileage and the condition of the class that was drawn', function () {
        $pool = storagePool(6);
        $classes = storageRules()->marketStorage()['classes'];

        foreach (range(1, 400) as $n) {
            $outcome = storageUnits()->open("ranges-{$n}", 'medium', $pool, 0);
            $range = $classes[$outcome->class];

            expect($outcome->ageYears)->toBeBetween($range['age_years']['min'], $range['age_years']['max'])
                ->and($outcome->conditionPpm)->toBeBetween($range['condition_ppm']['min'], $range['condition_ppm']['max'])
                ->and($outcome->mileageKm)->toBeBetween($range['mileage_km']['min'] - 500, $range['mileage_km']['max'] + 500)
                ->and($outcome->valueCents)->toBeGreaterThanOrEqual(100)
                ->and($outcome->defects)->toBeBetween(0, 5);
        }
    });

    it('draw the classes in the odds of the kind of unit', function (string $unit, int $level) {
        $pool = storagePool($level);
        $counts = ['scrap' => 0, 'junk' => 0, 'good' => 0, 'gem' => 0];
        $n = 6_000;

        foreach (range(1, $n) as $i) {
            $counts[storageUnits()->open("odds-{$unit}-{$i}", $unit, $pool, 0)->class]++;
        }

        foreach (storageUnits()->oddsPercent($unit) as $class => $percent) {
            // Within two points (and a share of the odds for the rare ones): 6 000 draws are enough for that.
            expect(abs(100 * $counts[$class] / $n - $percent))->toBeLessThan(max(2.0, $percent * 0.35));
        }
    })->with([
        'a small unit' => ['small', 3],
        'a medium unit' => ['medium', 6],
        'a large unit' => ['large', 9],
    ]);

    it('are never worth more than their price when the car is sold at once: the loss is what the unit costs', function (string $unit, int $level) {
        $pool = storagePool($level);
        $price = storageRules()->marketStorage()['units'][$unit]['price_cents'];
        $paid = 0;
        $n = 20_000;
        $sinceGood = 0;

        foreach (range(1, $n) as $i) {
            $outcome = storageUnits()->open("ev-{$unit}-{$i}", $unit, $pool, $sinceGood);
            $sinceGood = in_array($outcome->class, [StorageOutcome::GOOD, StorageOutcome::GEM], true) ? 0 : $sinceGood + 1;
            $paid += storageSale($outcome, $pool);
        }

        // Selling every car at once returns between 70% and 95% of the price, with the guarantee too (it lifts a small unit from 78% to 92%, and
        // the others hardly at all): opening units is a sink of money, never a way to make it.
        expect($paid / $n / $price)->toBeBetween(0.7, 0.95);
    })->with([
        'a small unit' => ['small', 3],
        'a medium unit' => ['medium', 6],
        'a large unit' => ['large', 9],
    ]);

    it('give a good car or a gem when the guarantee is due, and not before', function () {
        $pool = storagePool(3);
        $every = storageUnits()->pityEvery();

        foreach (range(1, 120) as $n) {
            expect(storageUnits()->open("pity-{$n}", 'small', $pool, $every - 1)->class)->toBeIn([StorageOutcome::GOOD, StorageOutcome::GEM]);
        }

        // Before it is due the odds are the plain ones: most of the units of a small kind are scrap.
        $scrap = 0;

        foreach (range(1, 120) as $n) {
            $scrap += storageUnits()->open("pity-{$n}", 'small', $pool, $every - 3)->class === StorageOutcome::SCRAP ? 1 : 0;
        }

        expect($scrap)->toBeGreaterThan(60);
    });

    it('never give a gem worth more than the cap of a small unit', function () {
        $pool = storagePool(3);
        $cap = storageRules()->marketStorage()['units']['small']['gem_value_cap_cents'];
        $gems = 0;

        // The guarantee makes a good car or a gem of every draw, so the gems come quickly.
        foreach (range(1, 2_000) as $n) {
            $outcome = storageUnits()->open("cap-{$n}", 'small', $pool, storageUnits()->pityEvery());

            if ($outcome->class === StorageOutcome::GEM) {
                $gems++;
                expect($outcome->valueCents)->toBeLessThanOrEqual($cap);
            }
        }

        expect($gems)->toBeGreaterThan(5);
    });

    it('say the odds in percent', function () {
        expect(storageUnits()->oddsPercent('small'))->toBe(['scrap' => 70.0, 'junk' => 25.0, 'good' => 4.5, 'gem' => 0.5])
            ->and(storageUnits()->oddsPercent('large'))->toBe(['scrap' => 15.0, 'junk' => 30.0, 'good' => 40.0, 'gem' => 15.0])
            ->and(array_keys(storageUnits()->units()))->toBe(['small', 'medium', 'large']);
    });
});
