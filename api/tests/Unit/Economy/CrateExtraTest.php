<?php

declare(strict_types=1);

use App\Domain\Economy\CrateEconomy;
use App\Domain\Economy\CrateExtra;
use App\Domain\Economy\DayState;

/**
 * Crates made of numbers: the odds and the guarantees of the game, a gain that grows by a tenth, a twentieth, ... for the first crates of a tier.
 *
 * @param  array<string, mixed>  $with  what to change, by the name of the constructor argument
 */
function aCrateEconomy(array $with = []): CrateEconomy
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
        'gain' => [0.0, 0.10, 0.15, 0.18, 0.20],
        ...$with,
    ]);
}

/** A day of the regular player: with this income, cash in hand and level, on this day. */
function aCrateDay(float $income = 1_000.0, float $cash = 100_000.0, int $level = 1, int $day = 1): DayState
{
    return new DayState(economyRules(), economyRules()->players()['A'], $day, 0, $level, $income, $cash);
}

describe('the tiers and the price of a crate', function () {
    it('opens the tier of a level as the game does: the first at the first level, and one more every three levels', function () {
        $crates = aCrateEconomy();
        $tier = fn (int $level): int => $crates->tierAt(economyRules(), $level);

        expect([$tier(1), $tier(2), $tier(3), $tier(5), $tier(6), $tier(9), $tier(12)])->toBe([1, 1, 2, 2, 3, 4, 5]);
    });

    it('has no crate to sell below the first level that opens one', function () {
        expect(aCrateEconomy(['firstTierLevel' => 5])->tierAt(economyRules(), 4))->toBe(0)
            ->and(aCrateEconomy(['firstTierLevel' => 5])->tierAt(economyRules(), 5))->toBe(2);
    });

    it('stops at the highest tier that the anchors have a price for', function () {
        // The anchors know ten tiers. A level that would open the thirtieth opens the tenth.
        expect(aCrateEconomy()->tierAt(economyRules(), 90))->toBe(10);
    });

    it('prices a crate as the mean of the anchor prices of the parts of its slots, by the mix, times the price factor', function () {
        $rules = economyRules();
        // Tier 1: tires $450 (6% of half of $15,000), suspension $750 (10%) and brakes $600 (8%): (2 * 450 + 750 + 600) / 4.
        expect(aCrateEconomy()->cost($rules, 1))->toEqualWithDelta(562.5, 1e-9)
            ->and(aCrateEconomy(['priceFactorPpm' => 1_500_000])->cost($rules, 1))->toEqualWithDelta(843.75, 1e-9)
            ->and(aCrateEconomy()->cost($rules, 2))->toBeGreaterThan(aCrateEconomy()->cost($rules, 1))
            ->and(fn () => aCrateEconomy()->cost($rules, 11))->toThrow(InvalidArgumentException::class);
    });

    it('refuses numbers that make no crates', function () {
        expect(fn () => aCrateEconomy(['levelsPerTier' => 0]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => aCrateEconomy(['slotMix' => []]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => aCrateEconomy(['gain' => [0.1, 0.2]]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => aCrateEconomy(['floorRarity' => 'mythic']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => aCrateEconomy(['odds' => ['common' => 1]]))->toThrow(InvalidArgumentException::class);
    });
});

describe('the gain of the table', function () {
    it('is the one of the number of crates, and stays at the last one when there are more', function () {
        $crates = aCrateEconomy();

        expect([$crates->gainAfter(0), $crates->gainAfter(1), $crates->gainAfter(4), $crates->gainAfter(50), $crates->gainAfter(-3)])->toBe([0.0, 0.10, 0.20, 0.20, 0.0]);
    });
});

describe('the crates a half of the players need', function () {
    it('are exact for the odds and the guarantees of the game: a rare part in 5 crates, an epic one in 18, and a legendary one at the guarantee of 150', function () {
        // The mean of the table of the pace (drawn players, the crate in the middle) says 6, 19 and 150: the same within one.
        expect(aCrateEconomy()->mediansToFirst())->toBe(['rare' => 5, 'epic' => 18, 'legendary' => 150]);
    });

    it('follow the guarantees: with a rare part at every crate the first one comes at the first, and the legendary guarantee is the end of the road', function () {
        expect(aCrateEconomy(['floorEvery' => 1])->mediansToFirst()['rare'])->toBe(1)
            ->and(aCrateEconomy(['legendaryEvery' => 4])->mediansToFirst()['legendary'])->toBeLessThanOrEqual(4)
            ->and(aCrateEconomy(['legendaryEvery' => 4])->mediansToFirst()['epic'])->toBeLessThanOrEqual(4);
    });
});

describe('the crates a player opens', function () {
    it('are paid for out of a share of the income, a day at a time, and what is left is carried to the next day', function () {
        $extra = new CrateExtra(0.2, aCrateEconomy());

        // A fifth of $1,000 is $200 a day, and a crate of the first tier is $562.50: none on the first two days, one on the third.
        $one = $extra->day(aCrateDay(day: 1));
        $two = $extra->day(aCrateDay(day: 2));
        $three = $extra->day(aCrateDay(day: 3));
        $four = $extra->day(aCrateDay(day: 4));

        expect([$one->cash, $two->cash, $three->cash, $four->cash])->toBe([-0.0, -0.0, -562.5, -0.0])
            // On the fourth day $37.50 (the $600 of three days less the crate) and $200 more are in hand, which is not a crate.
            ->and($extra->report()['crates'])->toBe(1)
            ->and($extra->report()['spent'])->toBe(562.5);
    });

    it('add the gain of the crates of the tier to the income of the car: the factor is one plus the gain after them', function () {
        $extra = new CrateExtra(1.0, aCrateEconomy());

        $none = $extra->day(aCrateDay(income: 300.0));
        $one = $extra->day(aCrateDay(income: 300.0, day: 2));
        $two = $extra->day(aCrateDay(income: 5_000.0, day: 3));

        // $300 is not a crate, $600 is one (the gain of one crate is 0.10), and $5,300 more is nine crates, and the table ends at 0.20.
        expect([$none->incomeFactor, $one->incomeFactor, $two->incomeFactor])->toBe([1.0, 1.1, 1.2])
            ->and($one->cash)->toBe(-562.5);
    });

    it('never open more crates than the cash of the player pays for, and keep the budget for later', function () {
        $extra = new CrateExtra(1.0, aCrateEconomy());

        $first = $extra->day(aCrateDay(income: 1_000.0, cash: 0.0));
        // The budget is $1,437.50 now, and the cash and the income $1,000: one crate, not two.
        $second = $extra->day(aCrateDay(income: 1_000.0, cash: 0.0, day: 2));

        expect($first->cash)->toBe(-562.5)
            ->and($second->cash)->toBe(-562.5)
            ->and($extra->report()['crates'])->toBe(2);
    });

    it('begin again at the first crate when the level opens a higher tier, because the parts of the new tier are common', function () {
        $extra = new CrateExtra(1.0, aCrateEconomy());

        $before = $extra->day(aCrateDay(income: 2_000.0, level: 2));
        // At level 3 the second tier opens, whose crate costs more than the $100 that this day brings.
        $after = $extra->day(aCrateDay(income: 100.0, level: 3, day: 2));
        $report = $extra->report();

        expect($before->incomeFactor)->toBeGreaterThan(1.1)
            ->and($after->incomeFactor)->toBe(1.0)
            ->and($report['tier'])->toBe(2)
            ->and($report['crates_in_tier'])->toBe(0)
            ->and($report['crates'])->toBe(3);
    });

    it('open nothing at a share of nothing, or below the first level that opens a crate', function () {
        $none = (new CrateExtra(0.0, aCrateEconomy()))->day(aCrateDay());
        $early = (new CrateExtra(1.0, aCrateEconomy(['firstTierLevel' => 5])))->day(aCrateDay(level: 3));

        expect($none->cash)->toBe(0.0)->and($none->incomeFactor)->toBe(1.0)
            ->and($early->cash)->toBe(0.0)->and($early->incomeFactor)->toBe(1.0);
    });

    it('say the first day on which the crates of a tier were as many as the half of the players need', function () {
        // With a guarantee at every crate and a legendary one at every fourth, the half of the players have a rare part after one crate.
        $extra = new CrateExtra(1.0, aCrateEconomy(['floorEvery' => 1, 'legendaryEvery' => 4]));
        $medians = (aCrateEconomy(['floorEvery' => 1, 'legendaryEvery' => 4]))->mediansToFirst();

        foreach (range(1, 12) as $day) {
            $extra->day(aCrateDay(income: 600.0, day: $day));
        }

        $report = $extra->report();

        expect($report['first_rare_day'])->toBe(1)
            ->and($report['medians'])->toBe($medians)
            ->and($report['first_legendary_day'])->not->toBeNull()
            ->and($report['first_legendary_day'])->toBeGreaterThanOrEqual($report['first_epic_day'])
            ->and($report)->toHaveKeys(['share', 'crates', 'spent', 'uplift', 'tier', 'crates_in_tier', 'priced_by']);
    });

    it('is called crates, and adds nothing to the cost of a step of the ladder', function () {
        $extra = new CrateExtra(0.2, aCrateEconomy());

        expect($extra->name())->toBe('crates')->and($extra->stepCostDelta(economyRules(), 3))->toBe(0.0);
    });
});
