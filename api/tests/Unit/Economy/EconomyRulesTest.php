<?php

declare(strict_types=1);

use App\Domain\Economy\EconomyRules;

/*
| The numbers of the table of Systemy VI, point 17, which the anchors have to give.
*/
it('gives the profit of an hour of driving at every rung: 600 * 1.15 ^ rung', function () {
    $rules = economyRules();
    $expected = [600, 690, 793, 913, 1049, 1207, 1388, 1596, 1835];

    foreach ($expected as $rung => $dollars) {
        expect(round($rules->netPerHour($rung)))->toBe((float) $dollars);
    }
});

it('gives the reward of a lap with the fuel it burns, as the table of the design does', function () {
    $rules = economyRules();
    $expected = [15.5, 18.5, 22.5, 28.0, 35.5, 45.4, 55.6, 68.2, 84.1];

    foreach ($expected as $rung => $dollars) {
        expect(round($rules->grossPerLap($rung), 1))->toBe($dollars);
    }
});

it('gives the hours a car takes to pay for itself', function () {
    $rules = economyRules();
    $expected = [3.3, 6.5, 13.9, 24.1, 36.2, 58.0, 108.1, 206.8, 425.0];

    foreach ($expected as $rung => $hours) {
        expect(round($rules->paybackHours($rung), 1))->toBe($hours);
    }
});

it('gives the daily income of a regular player, with the fleet that grows from one car to one and seven tenths', function () {
    $rules = economyRules();
    $regular = $rules->players()['A'];
    // The table of the design rounds to ten dollars.
    $expected = [1010, 2330, 5620, 12330, 24080, 32320, 37160, 42730, 49130];

    foreach ($expected as $rung => $dollars) {
        expect(abs($rules->dailyIncome($regular, $rung) - $dollars))->toBeLessThan($dollars * 0.005);
    }
});

it('hours of driving are what the visits pay for, up to what a day has', function () {
    $rules = economyRules();

    expect($rules->players()['A']->hoursPerDay($rules->offlineWindowHours(0)))->toBe(1.5)
        ->and($rules->players()['B']->hoursPerDay($rules->offlineWindowHours(0)))->toBe(0.5)
        ->and($rules->players()['A']->hoursPerDay($rules->offlineWindowHours(8)))->toBe(14.0)
        ->and($rules->players()['B']->hoursPerDay($rules->offlineWindowHours(8)))->toBe(12.0)
        ->and($rules->players()['C']->hoursPerDay($rules->offlineWindowHours(8)))->toBe(18.0);
});

it('prices a step up as the next car less 70 percent of what the old one is sold for', function () {
    $rules = economyRules();

    // L1 costs 4 500, and the wreck sells for 70% of 2 000.
    expect($rules->stepCost(0))->toBe(4_500 - 0.7 * 2_000);
});

it('reaches a level with the table of the first levels, a straight line to the catch-up level, and 450 * level ^ 2.3 from there', function () {
    $rules = economyRules();

    // The table: level 2 in three laps of 20 XP, level 10 in 640.
    expect($rules->xpToReach(2))->toBe(60.0)
        ->and($rules->xpToReach(3))->toBe(180.0)
        ->and($rules->xpToReach(10))->toBe(12_800.0)
        // The levels between are on a straight line from level 10 to the curve at level 20, which is where the curve of the design takes over.
        ->and(round($rules->xpToReach(15)))->toBe(227_481.0)
        ->and(round($rules->xpToReach(20)))->toBe(round(450 * 20 ** 2.3))
        ->and(round($rules->xpToReach(25)))->toBe(round(450 * 25 ** 2.3))
        ->and($rules->levelAt(0))->toBe(1)
        ->and($rules->levelAt($rules->xpToReach(3) - 0.01))->toBe(2)
        ->and($rules->levelAt($rules->xpToReach(3)))->toBe(3)
        ->and($rules->levelAt($rules->xpToReach(9)))->toBe(9)
        ->and($rules->levelAt($rules->xpToReach(14)))->toBe(14);
});

it('says how much experience a lap of the first rung gives, for the rules of the game to be set against', function () {
    // 900 an hour, 48 laps an hour.
    expect(economyRules()->xpPerLap(0))->toBe(18.75);
});

it('prices a part from the new car of its tier, the share of the set and the share of the slot', function () {
    $rules = economyRules();

    // Tier 2 is a new car of $19 000: half of it is the set, and tires are 6% of the set.
    expect($rules->partPrice(2, 'tires'))->toBe(570.0)
        ->and($rules->partPrice(1, 'suspension'))->toBe(750.0)
        ->and($rules->partPrice(11, 'tires'))->toBeNull()
        ->and($rules->partPrice(2, 'flux-capacitor'))->toBeNull();
});

it('turns the knob of the rewards into the profit of an hour', function () {
    expect(economyRules(['knobs', 'reward_mult'], 1.1)->netPerHour(0))->toBe(660.0);
});

it('refuses a ruleset whose players and targets do not agree', function () {
    $data = economyData();
    $data['targets']['A'] = [4, 11];

    expect(fn () => EconomyRules::fromArray($data))->toThrow(InvalidArgumentException::class, 'needs a target day for each');
});

it('refuses a ruleset whose fleet is shorter than the ladder', function () {
    $data = economyData();
    $data['players']['B']['fleet'] = [1, 1];

    expect(fn () => EconomyRules::fromArray($data))->toThrow(InvalidArgumentException::class, 'fleet');
});
