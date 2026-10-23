<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Domain\Market\ExchangeListings;
use App\Domain\Market\UsedCarPricing;
use App\Domain\Market\UsedListing;
use App\Domain\Settlement\Ppm;

function exchangeOfRules(): ExchangeListings
{
    $rules = Rules::fromFile(dirname(__DIR__, 4).'/content/rules/starter.json');

    return new ExchangeListings($rules->marketExchange(), new UsedCarPricing($rules->marketPricing()));
}

/** @return list<array{slug: string, class: string, price_cents: int}> */
function carsForTheExchange(): array
{
    return [
        ['slug' => 'furgon-z-1', 'class' => 'van', 'price_cents' => 2_600_000],
        ['slug' => 'kornet-v6', 'class' => 'coupe', 'price_cents' => 3_000_000],
        ['slug' => 'rdzak-gt-3', 'class' => 'hatchback', 'price_cents' => 1_850_000],
    ];
}

describe('the listings of the exchange', function () {
    it('are the same for the same driver, city and refresh, and need nothing stored', function () {
        $first = exchangeOfRules()->listings('market:7', 'zlomowo', 1_000, carsForTheExchange());
        $second = exchangeOfRules()->listings('market:7', 'zlomowo', 1_000, carsForTheExchange());

        expect($first)->toEqual($second)->and($first)->toHaveCount(4);
    });

    it('change with the driver, the city and the refresh', function () {
        $listings = fn (string $seed, string $region, int $window) => array_map(fn (UsedListing $listing) => $listing->key, exchangeOfRules()->listings($seed, $region, $window, carsForTheExchange()));

        expect($listings('market:7', 'zlomowo', 1_000))->not->toBe($listings('market:8', 'zlomowo', 1_000))
            ->and($listings('market:7', 'zlomowo', 1_000))->not->toBe($listings('market:7', 'kruszyn', 1_000))
            ->and($listings('market:7', 'zlomowo', 1_000))->not->toBe($listings('market:7', 'zlomowo', 1_001));
    });

    it('list nothing when there is no car that may be listed', function () {
        expect(exchangeOfRules()->listings('market:7', 'zlomowo', 1_000, []))->toBe([]);
    });

    it('keep to the ranges of the rules: the mileage, the age, the condition and the price', function () {
        for ($window = 0; $window < 300; $window++) {
            foreach (exchangeOfRules()->listings('market:7', 'zlomowo', $window, carsForTheExchange()) as $listing) {
                expect($listing->mileageKm)->toBeBetween(40_000, 280_000)
                    ->and($listing->mileageKm % 1_000)->toBe(0)
                    ->and($listing->ageYears)->toBeBetween(3, 25)
                    ->and($listing->conditionPpm)->toBeBetween(50_000, Ppm::ONE)
                    ->and($listing->priceCents)->toBeGreaterThanOrEqual(100)
                    ->and($listing->priceCents % 100)->toBe(0)
                    ->and(array_column(carsForTheExchange(), 'slug'))->toContain($listing->vehicle);
            }
        }
    });

    it('have a perfect condition only without defects, and fewer defects for fewer km', function () {
        $byBand = ['low' => [], 'high' => []];

        for ($window = 0; $window < 600; $window++) {
            foreach (exchangeOfRules()->listings('market:7', 'zlomowo', $window, carsForTheExchange()) as $listing) {
                expect($listing->conditionPpm === Ppm::ONE)->toBe($listing->defects === 0);

                if ($listing->mileageKm <= 100_000) {
                    $byBand['low'][] = $listing;
                } elseif ($listing->mileageKm > 180_000) {
                    $byBand['high'][] = $listing;
                }
            }
        }

        $average = fn (array $listings): float => array_sum(array_map(fn (UsedListing $listing) => $listing->defects, $listings)) / count($listings);
        $perfect = fn (array $listings): float => count(array_filter($listings, fn (UsedListing $listing) => $listing->defects === 0)) / count($listings);

        // The weights of the rules: 15% of the cars that have driven little are perfect, and 3% of those that have driven a lot.
        expect($average($byBand['low']))->toBeLessThan($average($byBand['high']))
            ->and($average($byBand['low']))->toBeLessThan(1.5)
            ->and($average($byBand['high']))->toBeGreaterThan(2.0)
            ->and($perfect($byBand['low']))->toBeBetween(0.08, 0.25)
            ->and($perfect($byBand['high']))->toBeLessThan(0.08);
    });

    it('say a word about the condition that is only roughly the truth', function () {
        $good = [];
        $poor = [];
        $wrong = 0;
        $all = 0;

        for ($window = 0; $window < 600; $window++) {
            foreach (exchangeOfRules()->listings('market:7', 'zlomowo', $window, carsForTheExchange()) as $listing) {
                $all++;

                match ($listing->word) {
                    UsedListing::GOOD => $good[] = $listing->conditionPpm,
                    UsedListing::POOR => $poor[] = $listing->conditionPpm,
                    default => null,
                };

                $truth = $listing->conditionPpm >= 750_000 ? UsedListing::GOOD : ($listing->conditionPpm >= 500_000 ? UsedListing::FAIR : UsedListing::POOR);
                $wrong += $truth === $listing->word ? 0 : 1;
            }
        }

        expect(array_sum($good) / count($good))->toBeGreaterThan(array_sum($poor) / count($poor))
            // Roughly: some listings are off, and most are not.
            ->and($wrong / $all)->toBeGreaterThan(0.02)
            ->and($wrong / $all)->toBeLessThan(0.4);
    });

    it('can be found again by their key while the refresh lasts, and not after it', function () {
        $exchange = exchangeOfRules();
        $nowMs = 1_800_000 * 7 + 5_000;
        $window = $exchange->window($nowMs);
        $listing = $exchange->listings('market:7', 'zlomowo', $window, carsForTheExchange())[2];

        expect($exchange->find($listing->key, 'market:7', $nowMs, carsForTheExchange()))->toEqual($listing)
            ->and($exchange->find($listing->key, 'market:7', $nowMs + 1_800_000, carsForTheExchange()))->toBeNull()
            ->and($exchange->find($listing->key, 'market:8', $nowMs, carsForTheExchange()))->toBeNull();
    });

    it('are not found by a key that was made up, or one of a car that is not on that place any more', function () {
        $exchange = exchangeOfRules();
        $nowMs = 1_800_000 * 7;
        $listing = $exchange->listings('market:7', 'zlomowo', 7, carsForTheExchange())[0];

        expect($exchange->find('zlomowo.7.0.00000000', 'market:7', $nowMs, carsForTheExchange()))->toBeNull()
            ->and($exchange->find('zlomowo.7.9.deadbeef', 'market:7', $nowMs, carsForTheExchange()))->toBeNull()
            ->and($exchange->find('not a key', 'market:7', $nowMs, carsForTheExchange()))->toBeNull()
            // The driver reached a level that opens another car: the places may hold other cars, so the old key does not buy a car they did not see.
            ->and($exchange->find($listing->key, 'market:7', $nowMs, array_slice(carsForTheExchange(), 0, 2)))->toBeNull();
    });

    it('count the refresh and say when the next one is', function () {
        $exchange = exchangeOfRules();

        expect($exchange->window(1_800_000 * 5 + 1))->toBe(5)
            ->and($exchange->nextRefreshMs(1_800_000 * 5 + 1))->toBe(1_800_000 * 6);
    });

    it('cost 3% of the price to inspect, in whole dollars', function () {
        expect(exchangeOfRules()->inspectionCostCents(814_000))->toBe(24_400)
            ->and(exchangeOfRules()->inspectionCostCents(362_000))->toBe(10_900)
            ->and(exchangeOfRules()->inspectionCostCents(100))->toBe(100);
    });
});
