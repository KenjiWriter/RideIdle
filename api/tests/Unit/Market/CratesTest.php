<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Domain\Market\Crates;
use App\Domain\Run\Rarity;

function crateRules(): Rules
{
    return Rules::fromFile(dirname(__DIR__, 4).'/content/rules/starter.json');
}

function crates(): Crates
{
    return new Crates(crateRules()->shopCrates());
}

/**
 * How the openings of a group came out, over many seeds, with a given count of crates since the guarantees.
 *
 * @return array<string, int> the number of parts of each rarity, by the key of the rarity
 */
function drawnRarities(string $group, int $sinceFloor, int $sinceLegendary, int $times, string $prefix = 'seed'): array
{
    $counts = array_fill_keys(array_map(static fn (Rarity $rarity): string => $rarity->key(), Rarity::cases()), 0);

    for ($n = 0; $n < $times; $n++) {
        $counts[crates()->draw("{$prefix}-{$n}", $group, $sinceFloor, $sinceLegendary)->key()]++;
    }

    return $counts;
}

describe('the rarity of a part', function () {
    it('is stored as a number from 1 to 5, and the rules and the API call it by its name', function () {
        expect(array_map(fn (Rarity $rarity): string => $rarity->key(), Rarity::cases()))->toBe(['common', 'uncommon', 'rare', 'epic', 'legendary'])
            ->and(array_map(fn (Rarity $rarity): int => $rarity->value, Rarity::cases()))->toBe([1, 2, 3, 4, 5])
            ->and(Rarity::fromKey('epic'))->toBe(Rarity::Epic)
            ->and(fn () => Rarity::fromKey('mythic'))->toThrow(InvalidArgumentException::class);
    });
});

describe('the crates', function () {
    it('shows the odds in percent with one decimal, as the board does', function () {
        expect(crates()->oddsPercent('standard'))->toBe(['common' => 62.0, 'uncommon' => 25.0, 'rare' => 10.0, 'epic' => 2.7, 'legendary' => 0.3])
            ->and(crates()->oddsPercent('premium'))->toBe(['common' => 30.0, 'uncommon' => 35.0, 'rare' => 24.0, 'epic' => 9.0, 'legendary' => 2.0]);
    });

    it('opens the tiers one after the other as the dealer does: tier 1 at level 1, then every three levels', function () {
        expect(array_map(fn (int $tier): int => crates()->playerLevelOfTier($tier), [1, 2, 3, 4]))->toBe([1, 3, 6, 9]);
    });

    it('draws the same part for the same seed, and another for another', function () {
        expect(crates()->draw('seed-1', 'standard', 0, 0))->toBe(crates()->draw('seed-1', 'standard', 0, 0))
            ->and(crates()->pick('seed-1', 'model', 7))->toBe(crates()->pick('seed-1', 'model', 7))
            ->and(array_unique(array_map(fn (int $n): int => crates()->pick("seed-{$n}", 'model', 7), range(1, 60))))->toHaveCount(7);
    });

    it('picks a place within the count, and always the first when there is one model', function () {
        foreach (range(1, 300) as $n) {
            expect(crates()->pick("pick-{$n}", 'slot', 4))->toBeBetween(0, 3)
                ->and(crates()->pick("pick-{$n}", 'model', 1))->toBe(0);
        }
    });

    it('gives the odds of the table over many openings, within a point', function (string $group, array $expected) {
        $times = 20_000;
        $counts = drawnRarities($group, 0, 0, $times);

        foreach ($expected as $key => $percent) {
            expect(100 * $counts[$key] / $times)->toBeBetween($percent - 1.0, $percent + 1.0, $key);
        }
    })->with([
        'standard' => ['standard', ['common' => 62.0, 'uncommon' => 25.0, 'rare' => 10.0, 'epic' => 2.7, 'legendary' => 0.3]],
        'premium' => ['premium', ['common' => 30.0, 'uncommon' => 35.0, 'rare' => 24.0, 'epic' => 9.0, 'legendary' => 2.0]],
    ]);

    it('gives a rare part or a better one at the 10th crate of the standard group, in the proportion of their odds', function () {
        $counts = drawnRarities('standard', 9, 0, 6_000);

        expect($counts['common'])->toBe(0)->and($counts['uncommon'])->toBe(0)
            // 100 : 27 : 3 of the odds are 77%, 21% and 2% of what is left.
            ->and(100 * $counts['rare'] / 6_000)->toBeBetween(75.0, 80.0)
            ->and(100 * $counts['epic'] / 6_000)->toBeBetween(18.0, 24.0);
    });

    it('does not give the guarantee a crate early', function () {
        $counts = drawnRarities('standard', 8, 0, 2_000);

        expect($counts['common'])->toBeGreaterThan(0);
    });

    it('gives an epic part or a better one at the 40th crate of the premium group', function () {
        $counts = drawnRarities('premium', 39, 0, 3_000);

        expect($counts['common'] + $counts['uncommon'] + $counts['rare'])->toBe(0)
            ->and($counts['epic'])->toBeGreaterThan(0)
            ->and($counts['legendary'])->toBeGreaterThan(0);
    });

    it('gives a legendary part at the 150th crate without one, whatever the other guarantee says', function () {
        expect(drawnRarities('standard', 3, 149, 500)['legendary'])->toBe(500)
            ->and(drawnRarities('standard', 9, 149, 500)['legendary'])->toBe(500)
            ->and(drawnRarities('premium', 0, 149, 500)['legendary'])->toBe(500)
            // One crate earlier there is no such thing.
            ->and(drawnRarities('standard', 3, 148, 500)['legendary'])->toBeLessThan(10);
    });

    it('prices the tiers by a schedule that says from which tier a factor holds, and leaves the tiers below it at none', function () {
        // From the rules: no factor up to the tier 6, 63% more from the tier 7 and 99% from the tier 8 on (the position among the bots makes a crate worth more
        // to the car; the pack has tiers 1 to 3 only, so the shop sells at the price of its parts).
        expect(array_map(fn (int $tier): int => crates()->tierPriceFactorPpm($tier), [1, 2, 3, 6, 7, 8, 9, 10]))->toBe([1_000_000, 1_000_000, 1_000_000, 1_000_000, 1_630_000, 1_990_000, 1_990_000, 1_990_000]);

        $steps = new Crates([...crateRules()->shopCrates(), 'tier_price_ppm' => ['1' => 1_000_000, '4' => 1_500_000, '6' => 2_000_000]]);

        expect(array_map(fn (int $tier): int => $steps->tierPriceFactorPpm($tier), [1, 3, 4, 5, 6, 10]))->toBe([1_000_000, 1_000_000, 1_500_000, 1_500_000, 2_000_000, 2_000_000]);
    });
});
