<?php

declare(strict_types=1);

use App\Domain\Economy\DayState;
use App\Domain\Economy\EconomyRules;
use App\Domain\Economy\PlayerRun;
use App\Domain\Economy\Purchase;
use App\Domain\Economy\SalesExtra;
use App\Domain\Economy\Simulator;

/** @return array<string, list<int>> the days of the purchases of the ladder in the scenario game with the sale of a car at what was paid, by kind of player */
function salesDaysOf(?EconomyRules $rules = null): array
{
    return array_map(
        static fn (PlayerRun $run): array => array_map(static fn (Purchase $purchase): int => $purchase->day, $run->purchases),
        (new Simulator)->simulateAllGame($rules ?? economyRules(), static fn (): array => [new SalesExtra]),
    );
}

describe('the sale of a car at what was paid for it', function () {
    it('is called sales, and does nothing in a day', function () {
        $extra = new SalesExtra;
        $effect = $extra->day(new DayState(economyRules(), economyRules()->players()['A'], 1, 0, 1, 1_000.0, 500.0));

        expect($extra->name())->toBe('sales')
            ->and($effect->cash)->toBe(0.0)
            ->and($effect->incomeFactor)->toBe(1.0);
    });

    it('makes the step from the wreck dearer by the share of what the wreck was worth less than the anchors say: 70% of $2,000 less $1,140', function () {
        // The anchors have the first car at $2,000 and the yard sells it for $1,140: a sale pays 70% of what was paid, so the step costs $602 more.
        expect((new SalesExtra)->stepCostDelta(economyRules(), 0))->toEqualWithDelta(0.7 * (2_000 - 1_140), 1e-9);
    });

    it('adds nothing to the steps from the cars of the ladder, which were bought at the value of the anchors', function () {
        $extra = new SalesExtra;

        foreach (range(1, economyRules()->rungs() - 2) as $rung) {
            expect($extra->stepCostDelta(economyRules(), $rung))->toBe(0.0);
        }
    });

    it('takes the price of a wreck from the fleet of the ruleset, and the share of a sale from its step cost', function () {
        expect((new SalesExtra)->stepCostDelta(economyRules(['model', 'fleet', 'wreck_price'], 1_600), 0))->toEqualWithDelta(0.7 * 400, 1e-9)
            ->and((new SalesExtra)->stepCostDelta(economyRules(['model', 'fleet', 'wreck_price'], 2_000), 0))->toEqualWithDelta(0.0, 1e-9)
            // A wreck that cost more than the anchors say makes the step cheaper.
            ->and((new SalesExtra)->stepCostDelta(economyRules(['model', 'fleet', 'wreck_price'], 2_500), 0))->toEqualWithDelta(-0.7 * 500, 1e-9)
            ->and((new SalesExtra)->stepCostDelta(economyRules(['model', 'resale'], 0.5), 0))->toEqualWithDelta(0.5 * 860, 1e-9);
    });

    it('says in the notes of the run what share it found and what it added to the steps it was asked about', function () {
        $run = (new Simulator)->simulateGame(economyRules(), economyRules()->players()['A'], [new SalesExtra]);

        expect($run->notes['sales']['resale'])->toEqualWithDelta(0.7, 1e-12)
            ->and($run->notes['sales']['deltas'][0])->toEqualWithDelta(602.0, 1e-9)
            ->and(array_sum(array_slice($run->notes['sales']['deltas'], 1)))->toBe(0.0)
            // The steps from rung 0 to 7 were asked about, and none from the last rung.
            ->and(array_keys($run->notes['sales']['deltas']))->toBe(range(0, 7));
    });

    it('says nothing of the share before a step was asked about', function () {
        expect((new SalesExtra)->report())->toBe(['resale' => null, 'deltas' => []]);
    });

    it('moves the first purchases of the ladder, and the last one by a few days at most', function () {
        $none = array_map(
            static fn (PlayerRun $run): array => array_map(static fn (Purchase $purchase): int => $purchase->day, $run->purchases),
            (new Simulator)->simulateAllGame(economyRules()),
        );
        $sold = salesDaysOf();

        // The step from the wreck is $602 dearer, and the player, who buys what pays back soonest, goes for the garages before the first car of the
        // ladder: that is why the first car comes days later (A on the 8th day and not the 3rd, B on the 23rd and not the 7th), and the long game
        // is the same within a day for the regular and the intensive player, and five days later for the occasional one, whose money binds the days
        // for longer. It is a choice of the policy that the dearer step brings out, and not a loss of 600 dollars that slow.
        expect($sold)->toBe([
            'A' => [8, 8, 9, 19, 29, 37, 49, 70],
            'B' => [23, 25, 29, 66, 102, 126, 153, 190],
            'C' => [3, 4, 4, 7, 12, 17, 25, 37],
        ]);

        expect(end($sold['A']) - end($none['A']))->toBeBetween(0, 1)
            ->and(end($sold['C']) - end($none['C']))->toBeBetween(0, 1)
            ->and(end($sold['B']) - end($none['B']))->toBeBetween(0, 5);
    });

    it('does not make any purchase of the ladder come sooner than it did', function () {
        $none = (new Simulator)->simulateAllGame(economyRules());

        foreach (salesDaysOf() as $key => $days) {
            foreach ($days as $index => $day) {
                expect($day)->toBeGreaterThanOrEqual($none[$key]->purchases[$index]->day);
            }
        }
    });
});
