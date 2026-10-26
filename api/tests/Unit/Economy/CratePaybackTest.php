<?php

declare(strict_types=1);

use App\Domain\Economy\CratePayback;

describe('the payback of a crate against the one of a step of the ladder', function () {
    it('divides the price of a crate by what the next one adds to the income of the car of the rung, and the cost of the step by what the next car adds', function () {
        $rules = economyRules();
        $player = $rules->players()['A'];
        $crates = aCrateEconomy();

        // Rung 2 at level 6 (the tier of its crates is the third); the gain of the table grows by 0.10, 0.05 and 0.02 for the crates that follow.
        $rows = (new CratePayback)->compare($rules, $player, $crates, 2, 6, [0, 1, 3]);

        $price = $crates->cost($rules, 3);
        $income = $rules->carDailyIncome($player, 2);
        $stepDays = $rules->stepCost(2) / ($rules->carDailyIncome($player, 3) - $income);

        expect($rows)->toHaveCount(3)
            ->and($rows[0])->toMatchArray(['crates' => 0, 'price' => $price])
            ->and($rows[0]['crate_days'])->toEqualWithDelta($price / ($income * 0.10), 1e-9)
            ->and($rows[1]['crate_days'])->toEqualWithDelta($price / ($income * 0.05), 1e-9)
            ->and($rows[2]['crate_days'])->toEqualWithDelta($price / ($income * 0.02), 1e-9)
            ->and($rows[0]['step_days'])->toEqualWithDelta($stepDays, 1e-9);
    });

    it('gets slower with every crate that is opened, since each adds less than the one before', function () {
        $rows = (new CratePayback)->compare(economyRules(), economyRules()->players()['A'], aCrateEconomy(), 1, 3, [0, 1, 2, 3]);

        expect($rows[1]['crate_days'])->toBeGreaterThan($rows[0]['crate_days'])
            ->and($rows[2]['crate_days'])->toBeGreaterThan($rows[1]['crate_days'])
            ->and($rows[3]['crate_days'])->toBeGreaterThan($rows[2]['crate_days']);
    });

    it('says nothing for a crate that adds nothing, for a level with no crate open, and for a step at the end of the ladder', function () {
        $rules = economyRules();
        $player = $rules->players()['A'];
        $last = $rules->rungs() - 1;

        // After the last number of the table a crate adds nothing.
        $beyond = (new CratePayback)->compare($rules, $player, aCrateEconomy(), 1, 3, [4]);
        $locked = (new CratePayback)->compare($rules, $player, aCrateEconomy(['firstTierLevel' => 5]), 1, 3, [0]);
        $end = (new CratePayback)->compare($rules, $player, aCrateEconomy(), $last, 27, [0]);

        expect($beyond[0]['crate_days'])->toBeNull()
            ->and($locked[0])->toMatchArray(['price' => null, 'crate_days' => null])
            ->and($end[0]['step_days'])->toBeNull()
            ->and($end[0]['crate_days'])->not->toBeNull();
    });

    it('compares at no crates, ten and forty, when it is not asked for others', function () {
        $rows = (new CratePayback)->compare(economyRules(), economyRules()->players()['B'], aCrateEconomy(['gain' => range(0, 50)]), 0, 1);

        expect(array_column($rows, 'crates'))->toBe([0, 10, 40]);
    });
});
