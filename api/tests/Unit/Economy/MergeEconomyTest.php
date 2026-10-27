<?php

declare(strict_types=1);

use App\Domain\Economy\CrateEconomy;
use App\Domain\Economy\MergeEconomy;

/**
 * Merging made of the numbers of the game: the bases of 80, 60, 40 and 25%, a point for the level of the mechanic, three points for every failure, at
 * most 90%, a fee of a tenth of p and a scrap of a quarter.
 *
 * @param  array<string, int>  $with  what to change: base_ppm (by rarity), level, pity, max, fee, scrap
 */
function aMergeEconomy(array $with = []): MergeEconomy
{
    $bases = ['uncommon' => 800_000, 'rare' => 600_000, 'epic' => 400_000, 'legendary' => 250_000];
    $level = $with['level'] ?? 10_000;
    $pity = $with['pity'] ?? 30_000;
    $max = $with['max'] ?? 900_000;
    $bases = [...$bases, ...($with['bases'] ?? [])];

    return new MergeEconomy(
        static fn (string $rarity, int $failures): int => max(0, min($max, $bases[$rarity] + $level + $pity * $failures)),
        $with['fee'] ?? 100_000,
        $with['scrap'] ?? 250_000,
    );
}

/** The expected number of attempts by a sum of the chances to have failed so far, long enough that the tail is nothing. */
function bruteForceAttempts(MergeEconomy $merge, string $rarity): float
{
    $survive = 1.0;
    $sum = 0.0;

    for ($failures = 0; $failures < 5_000; $failures++) {
        $sum += $survive;
        $survive *= 1 - $merge->chance($rarity, $failures) / 1_000_000;
    }

    return $sum;
}

describe('the attempts of a step', function () {
    it('is the geometric mean of the chance when nothing grows: the design table of 2.5, 8.3, 42 and 333 commons', function () {
        $merge = aMergeEconomy(['level' => 0, 'pity' => 0]);
        $commons = array_column($merge->steps(), 'commons');

        expect($merge->attempts('uncommon'))->toEqualWithDelta(1 / 0.8, 1e-12)
            ->and($merge->attempts('legendary'))->toEqualWithDelta(4.0, 1e-12)
            ->and($commons[0])->toEqualWithDelta(2 / 0.8, 1e-9)
            ->and($commons[1])->toEqualWithDelta(2 / 0.8 * 2 / 0.6, 1e-9)
            ->and($commons[2])->toEqualWithDelta(2 / 0.8 * 2 / 0.6 * 2 / 0.4, 1e-9)
            ->and($commons[3])->toEqualWithDelta(2 / 0.8 * 2 / 0.6 * 2 / 0.4 * 2 / 0.25, 1e-9)
            ->and(array_map(static fn (float $value): float => round($value, 1), $commons))->toBe([2.5, 8.3, 41.7, 333.3]);
    });

    it('equals the sum of the chances to have failed so far, which is how it is worked out, but without the tail that is cut off', function () {
        $merge = aMergeEconomy();

        foreach (MergeEconomy::STEPS as $rarity) {
            expect($merge->attempts($rarity))->toEqualWithDelta(bruteForceAttempts($merge, $rarity), 1e-9);
        }
    });

    it('is the numbers of the game with the point of the level and the protection from bad luck: 1.22, 1.60, 2.25 and 3.18 attempts', function () {
        $merge = aMergeEconomy();

        expect(array_map(fn (string $rarity): float => round($merge->attempts($rarity), 2), MergeEconomy::STEPS))->toBe([1.22, 1.6, 2.25, 3.18])
            ->and(array_map(fn (string $rarity): float => round($merge->attempts($rarity, pity: false), 2), MergeEconomy::STEPS))->toBe([1.23, 1.64, 2.44, 3.85]);
    });

    it('takes fewer attempts with the protection from bad luck, and the more the lower the chance', function () {
        $merge = aMergeEconomy();
        $saved = array_map(fn (string $rarity): float => $merge->attempts($rarity, pity: false) - $merge->attempts($rarity), MergeEconomy::STEPS);

        expect($saved[0])->toBeGreaterThan(0.0)
            ->and($saved)->toBe(array_values(array_map(static fn (float $value): float => $value, $saved)))
            ->and($saved[3])->toBeGreaterThan($saved[2])->and($saved[2])->toBeGreaterThan($saved[1])->and($saved[1])->toBeGreaterThan($saved[0]);
    });

    it('never lets the chance go over the cap, so a chance at the cap is the same attempt again and again', function () {
        // A step that begins at the cap with a point of the level: 89% + 1 point is 90%, and the protection adds nothing.
        $merge = aMergeEconomy(['bases' => ['uncommon' => 890_000]]);

        expect($merge->chance('uncommon', 0))->toBe(900_000)
            ->and($merge->chance('uncommon', 100))->toBe(900_000)
            ->and($merge->attempts('uncommon'))->toEqualWithDelta(1 / 0.9, 1e-12)
            // A chance that grows reaches the cap and stays: the step of 80% is at 90% after three failures.
            ->and(aMergeEconomy()->chance('uncommon', 3))->toBe(900_000)
            ->and(aMergeEconomy()->chance('uncommon', 50))->toBe(900_000);
    });

    it('refuses a step that has no chance, a negative fee, and a rarity that merging does not make', function () {
        expect(fn () => aMergeEconomy(['bases' => ['rare' => 0], 'level' => 0]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => aMergeEconomy(['fee' => -1]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => aMergeEconomy()->attempts('common'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => aMergeEconomy()->chance('rare', -1))->toThrow(InvalidArgumentException::class);
    });
});

describe('the parts and the cash of a rarity', function () {
    it('costs the commons it uses, and the fees of every attempt on the way, less the scrap of every failed one', function () {
        $merge = aMergeEconomy();
        $uncommon = $merge->steps()[0];
        $attempts = $merge->attempts('uncommon');

        // One step from commons: the parts are 2 per attempt, the fee a tenth of p per attempt, the scrap a quarter of p per failure.
        expect($uncommon['commons'])->toEqualWithDelta(2 * $attempts, 1e-12)
            ->and($uncommon['fees'])->toEqualWithDelta($attempts * 0.1, 1e-12)
            ->and($uncommon['scrap'])->toEqualWithDelta(($attempts - 1) * 0.25, 1e-12)
            ->and($uncommon['cost'])->toEqualWithDelta(2 * $attempts + $attempts * 0.1 - ($attempts - 1) * 0.25, 1e-12);

        // The next step uses two of these, valued at what they cost, so the cost is the recursion of the design.
        $rare = $merge->steps()[1];
        $rareAttempts = $merge->attempts('rare');

        expect($rare['cost'])->toEqualWithDelta($rareAttempts * (2 * $uncommon['cost'] + 0.1) - ($rareAttempts - 1) * 0.25, 1e-9);
    });

    it('costs more for every rarity, in parts and in cash, and the fees and the scrap grow with it', function () {
        $steps = aMergeEconomy()->steps();

        foreach (['commons', 'commons_no_pity', 'fees', 'scrap', 'cost'] as $column) {
            $values = array_column($steps, $column);
            $sorted = $values;
            sort($sorted);

            expect($values)->toBe($sorted, $column);
        }

        // The cash is more than the parts alone when the fee is dearer than the scrap, and less when it is not.
        expect(aMergeEconomy(['fee' => 0, 'scrap' => 0])->steps()[3]['cost'])->toEqualWithDelta($steps[3]['commons'], 1e-9)
            ->and(aMergeEconomy(['scrap' => 0])->steps()[3]['cost'])->toBeGreaterThan(aMergeEconomy()->steps()[3]['cost'])
            ->and(aMergeEconomy(['fee' => 0])->steps()[3]['cost'])->toBeLessThan($steps[3]['commons']);
    });

    it('takes fewer commons and less cash with the protection from bad luck than without it', function () {
        $with = aMergeEconomy()->steps();
        $without = aMergeEconomy(['pity' => 0])->steps();

        foreach ([0, 1, 2, 3] as $step) {
            expect($with[$step]['commons'])->toBeLessThan($without[$step]['commons'])
                ->and($with[$step]['cost'])->toBeLessThan($without[$step]['cost'])
                // And the column without the protection is what the step is when it is turned off.
                ->and($with[$step]['commons_no_pity'])->toEqualWithDelta($without[$step]['commons'], 1e-9);
        }
    });

    it('comes to about 224 common parts for a legendary part with the protection and 304 without it, as the design measured', function () {
        $legendary = aMergeEconomy()->steps()[3];

        expect($legendary['commons'])->toEqualWithDelta(224.07, 0.01)
            ->and($legendary['commons_no_pity'])->toEqualWithDelta(303.77, 0.01);
    });
});

describe('the crates it is set against', function () {
    /** Crates of the odds of the game, to be asked how many of them a rarity takes. */
    function mergeCrates(array $with = []): CrateEconomy
    {
        return new CrateEconomy(...[
            'firstTierLevel' => 1,
            'levelsPerTier' => 3,
            'priceFactorPpm' => 1_000_000,
            'slotMix' => ['tires' => 2, 'suspension' => 1, 'brakes' => 1],
            'odds' => ['common' => 620, 'uncommon' => 250, 'rare' => 100, 'epic' => 27, 'legendary' => 3],
            'floorEvery' => 10,
            'floorRarity' => 'rare',
            'legendaryEvery' => 150,
            'gain' => [0.0, 0.10],
            ...$with,
        ]);
    }

    it('takes the mean crates to the first part of a rarity or a better one from the chain of the odds and the guarantees', function () {
        $means = mergeCrates()->meansToFirst();

        // Half the weights are the odds of the game; with a guarantee of rare every 10 and of legendary every 150, the means grow with the rarity and stay under the guarantee.
        expect(array_keys($means))->toBe(['uncommon', 'rare', 'epic', 'legendary'])
            ->and($means['uncommon'])->toBeGreaterThan(1.0)->and($means['uncommon'])->toBeLessThan(1 / 0.38 + 0.05)
            ->and($means['rare'])->toBeGreaterThan($means['uncommon'])
            ->and($means['epic'])->toBeGreaterThan($means['rare'])
            ->and($means['legendary'])->toBeGreaterThan($means['epic'])->and($means['legendary'])->toBeLessThan(150.0)
            // The mean is not the median, but they are close for a rarity that comes early.
            ->and($means['rare'])->toBeLessThan(10.0);
    });

    it('is the mean of a geometric chain when the odds are two rarities and the guarantees are far away, and one crate when the floor is every crate', function () {
        $two = mergeCrates(['odds' => ['common' => 1, 'uncommon' => 0, 'rare' => 0, 'epic' => 0, 'legendary' => 1], 'floorRarity' => 'legendary', 'floorEvery' => 1_000, 'legendaryEvery' => 1_000]);
        $always = mergeCrates(['floorEvery' => 1]);

        expect($two->meansToFirst()['legendary'])->toEqualWithDelta(2.0, 1e-9)
            ->and($two->meansToFirst()['rare'])->toEqualWithDelta(2.0, 1e-9)
            ->and($always->meansToFirst()['rare'])->toEqualWithDelta(1.0, 1e-12);
    });

    it('says for every rarity what the crates cost against the merging, and whether merging is the cheaper way', function () {
        $merge = aMergeEconomy();
        $means = ['uncommon' => 2.0, 'rare' => 10.0, 'epic' => 20.0, 'legendary' => 100.0];
        $rows = $merge->againstCrates($means, 1.5);

        expect(array_column($rows, 'crates'))->toBe([2.0, 10.0, 20.0, 100.0])
            ->and(array_column($rows, 'crate_cost'))->toBe([3.0, 15.0, 30.0, 150.0])
            ->and($rows[0]['ratio'])->toEqualWithDelta($rows[0]['cost'] / 3.0, 1e-12)
            // About 2.5 p against 3, 8 p against 15 and 36 p against 30; the legendary part takes 230 p of merging against 150.
            ->and(array_column($rows, 'cheaper'))->toBe([true, true, false, false])
            ->and(fn () => $merge->againstCrates(['uncommon' => 1.0], 1.0))->toThrow(InvalidArgumentException::class);
    });
});
