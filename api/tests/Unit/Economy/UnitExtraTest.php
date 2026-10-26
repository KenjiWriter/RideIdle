<?php

declare(strict_types=1);

use App\Domain\Economy\DayState;
use App\Domain\Economy\UnitEconomy;
use App\Domain\Economy\UnitExtra;

/** A small unit ($1,000, level 3, pays back 90%) and a large one ($5,000, level 6, 80%), as in the test of the table. */
function unitExtraEconomy(): UnitEconomy
{
    return new UnitEconomy([
        'small' => ['unlock_level' => 3, 'price' => 1_000.0, 'mean_return' => 0.9, 'quantiles' => [['p' => 0.0, 'dollars' => 100.0], ['p' => 50.0, 'dollars' => 500.0], ['p' => 100.0, 'dollars' => 5_000.0]]],
        'large' => ['unlock_level' => 6, 'price' => 5_000.0, 'mean_return' => 0.8, 'quantiles' => [['p' => 0.0, 'dollars' => 500.0], ['p' => 50.0, 'dollars' => 2_000.0], ['p' => 100.0, 'dollars' => 20_000.0]]],
    ]);
}

/** A day of the regular player, at a level, with some cash in the morning and what the car earns that day. */
function unitDay(int $level, float $income, float $cash = 0.0, int $rung = 0, int $day = 1): DayState
{
    return new DayState(economyRules(), economyRules()->players()['A'], $day, $rung, $level, $income, $cash);
}

describe('the storage units as an extra of the scenario game', function () {
    it('is called units, and adds nothing to the cost of a step', function () {
        $extra = new UnitExtra(0.1, unitExtraEconomy());

        expect($extra->name())->toBe('units')
            ->and($extra->stepCostDelta(economyRules(), 0))->toBe(0.0);
    });

    it('opens nothing while the budget does not pay for a unit, and carries it to the next day', function () {
        $extra = new UnitExtra(0.1, unitExtraEconomy());

        // A tenth of $3,000 is $300 a day: three days is $900, and the fourth day makes $1,200, which pays for a small unit and leaves $200.
        foreach ([1, 2, 3] as $day) {
            expect($extra->day(unitDay(3, 3_000.0, 5_000.0, day: $day))->cash)->toBe(0.0);
        }

        $effect = $extra->day(unitDay(3, 3_000.0, 5_000.0, day: 4));

        expect($effect->cash)->toEqualWithDelta(-100.0, 1e-9)
            ->and($effect->incomeFactor)->toBe(1.0)
            ->and($extra->report()['units'])->toBe(['small' => 1, 'large' => 0]);

        // The $200 that was left is in the budget of the next day: $200 and $300 is $500, and no unit.
        expect($extra->day(unitDay(3, 3_000.0, 5_000.0, day: 5))->cash)->toBe(0.0);
    });

    it('costs the part of the price that does not come back: a tenth of it for a kind that pays back 90%', function () {
        $extra = new UnitExtra(1.0, unitExtraEconomy());

        $effect = $extra->day(unitDay(3, 1_000.0, 5_000.0));
        $report = $extra->report();

        expect($effect->cash)->toEqualWithDelta(-100.0, 1e-9)
            ->and($report['spent'])->toBe(1_000.0)
            ->and($report['returned'])->toEqualWithDelta(900.0, 1e-9)
            ->and($report['net_cost'])->toEqualWithDelta(100.0, 1e-9);
    });

    it('opens as many units as the budget pays for, of the most expensive kind that the level opens', function () {
        $extra = new UnitExtra(1.0, unitExtraEconomy());

        // $12,000 at level 6: two large units ($10,000) and $2,000 left, which is not spent on small ones the same day but carried.
        $effect = $extra->day(unitDay(6, 12_000.0, 50_000.0));

        expect($extra->report()['units'])->toBe(['small' => 0, 'large' => 2])
            ->and($effect->cash)->toEqualWithDelta(-2 * 5_000 * 0.2, 1e-9);

        // At level 3 the large one is not open: the same budget is twelve small ones.
        $other = new UnitExtra(1.0, unitExtraEconomy());
        $other->day(unitDay(3, 12_000.0, 50_000.0));

        expect($other->report()['units'])->toBe(['small' => 12, 'large' => 0]);
    });

    it('opens nothing below the level of the cheapest kind, and carries the budget', function () {
        $extra = new UnitExtra(1.0, unitExtraEconomy());

        expect($extra->day(unitDay(2, 5_000.0, 5_000.0))->cash)->toBe(0.0);

        // The budget of the first day is still there when the level comes: $5,000 and $5,000 is two large units, if the level opens them.
        $extra->day(unitDay(6, 5_000.0, 50_000.0, day: 2));

        expect($extra->report()['units'])->toBe(['small' => 0, 'large' => 2]);
    });

    it('never opens more than the cash allows, which is what the player had and what the car earns that day, and drops the rest', function () {
        $extra = new UnitExtra(3.0, unitExtraEconomy());

        // The budget is $4,500, which is four small units, but the player has only the $1,500 that the car earns: one unit. The $500 that is
        // less than a whole unit is carried, and the three units that the cash did not allow are dropped.
        $extra->day(unitDay(3, 1_500.0, 0.0));

        expect($extra->report()['units'])->toBe(['small' => 1, 'large' => 0]);

        // The next day's budget is that $500 and $3,000 more: three units, with the cash for three.
        $extra->day(unitDay(3, 1_000.0, 5_000.0, day: 2));

        expect($extra->report()['units'])->toBe(['small' => 4, 'large' => 0]);
    });

    it('counts the chance that a find pays for a whole step of the ladder, and the days of income that the step stands for', function () {
        $extra = new UnitExtra(1.0, unitExtraEconomy());

        // The step from the wreck costs $3,100. In the table a sale of a small unit is at least that with the chance of
        // 1 - (50 + (3100 - 500) / 4500 * 50) / 100.
        $chance = 1 - (50 + (3_100 - 500) / 4_500 * 50) / 100;
        $extra->day(unitDay(3, 2_000.0, 5_000.0));
        $report = $extra->report();

        expect($report['expected_jumps'])->toEqualWithDelta(2 * $chance, 1e-9)
            // The step is 3 100 / 2 000 = 1.55 days of the income of the car.
            ->and($report['expected_jump_days'])->toEqualWithDelta(2 * $chance * 3_100 / 2_000, 1e-9);
    });

    it('counts no step for the car of the last rung, which has none to buy', function () {
        $extra = new UnitExtra(1.0, unitExtraEconomy());

        $extra->day(unitDay(3, 2_000.0, 5_000.0, rung: economyRules()->rungs() - 1));

        expect($extra->report()['expected_jumps'])->toBe(0.0)->and($extra->report()['units']['small'])->toBe(2);
    });

    it('says what it did: the share, the units, the money, and the net cost against the income of all the days', function () {
        $extra = new UnitExtra(0.5, unitExtraEconomy());

        $extra->day(unitDay(3, 2_000.0, 5_000.0));
        $extra->day(unitDay(3, 4_000.0, 5_000.0, day: 2));

        $report = $extra->report();

        // Half of $2,000 is one small unit, then half of $4,000 is two.
        expect(array_keys($report))->toBe(['share', 'units', 'spent', 'returned', 'net_cost', 'net_share_of_income', 'expected_jumps', 'expected_jump_days'])
            ->and($report['share'])->toBe(0.5)
            ->and($report['units'])->toBe(['small' => 3, 'large' => 0])
            ->and($report['spent'])->toBe(3_000.0)
            ->and($report['net_cost'])->toEqualWithDelta(300.0, 1e-9)
            // 300 of the 6 000 that the car earned on the two days.
            ->and($report['net_share_of_income'])->toEqualWithDelta(300 / 6_000, 1e-12);
    });

    it('says a net share of nothing before a day was played', function () {
        expect((new UnitExtra(0.1, unitExtraEconomy()))->report()['net_share_of_income'])->toBe(0.0);
    });
});
