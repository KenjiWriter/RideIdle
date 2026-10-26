<?php

declare(strict_types=1);

use App\Content\GameCrates;
use App\Content\GameUnits;
use App\Domain\Economy\ExtrasResult;
use App\Domain\Economy\ExtrasStudy;

/** The extras of the scenario game, with the numbers of the game as they are. */
function extrasStudied(array $only = []): ExtrasResult
{
    return app(ExtrasStudy::class)->run(economyRules(), app(GameCrates::class)->economy(), app(GameUnits::class)->economy(), $only);
}

describe('the extras of the scenario game, with the rules of the game as they are', function () {
    it('plays every kind of player at every share that the ruleset names, and the sale on the paid price', function () {
        $result = extrasStudied();

        expect(array_keys($result->baseline))->toBe(['A', 'B', 'C'])
            ->and(array_keys($result->crates))->toBe(['0.1', '0.2', '0.3'])
            ->and(array_keys($result->units))->toBe(['0.1', '0.2', '0.3'])
            ->and(array_keys($result->crates['0.2']))->toBe(['A', 'B', 'C'])
            ->and(array_keys($result->sales))->toBe(['A', 'B', 'C'])
            ->and($result->crates['0.2']['A']->notes['crates']['share'])->toBe(0.2)
            ->and($result->units['0.2']['A']->notes['units']['share'])->toBe(0.2)
            ->and(array_keys($result->sales['A']->notes))->toBe(['sales']);
    });

    it('plays only the kinds of players it is asked for', function () {
        $result = extrasStudied(['B']);

        expect(array_keys($result->baseline))->toBe(['B'])
            ->and(array_keys($result->crates['0.1']))->toBe(['B'])
            ->and($result->valuedPlayer)->toBe('B');
    });

    it('moves no day of the ladder by more than the tolerance with crates or with units, at any of the shares', function () {
        $result = extrasStudied();

        // The crates and the units cost less than a quarter of any day of the ladder, at a third of the income of the car or less.
        expect($result->crateShiftPassed())->toBeTrue()->and($result->unitShiftPassed())->toBeTrue();
    });

    it('says that the first crates pay back sooner than the next car from the rung where the cars become dear, and that the programme of crates does not', function () {
        $result = extrasStudied();

        // The first crates are the best a crate does (the gain falls with every crate), and the whole programme to the guarantee of a legendary part is the gate.
        expect($result->firstCratesSooner())->toBe(['L4', 'L5', 'L6', 'L7'])
            ->and($result->crateValuePassed())->toBeTrue()
            ->and($result->valuedPlayer)->toBe('A');
    });

    it('keeps the programme of crates just above the next car at the rung where it is the nearest: the dearest tier costs a little more', function () {
        $rows = collect(extrasStudied()->crateValue)->keyBy('rung');

        // The 150 crates of the tier 8 pay back in 148.8 days against the 145.6 of the car (and in 136.5 at the anchors' price, which would be out).
        expect($rows['L7']['programme_days'])->toBeBetween(146.0, 151.0)
            ->and($rows['L7']['step_days'])->toBeLessThan($rows['L7']['programme_days'])
            ->and($rows['L7']['price'])->toEqualWithDelta(7_500 * 1.09, 0.01)
            ->and($rows['L6']['price'])->toEqualWithDelta(3_750.0, 0.01)
            ->and($rows['L8']['step_days'])->toBeNull();
    });

    it('gives a crate of the first rung a payback in days, set against the days of the next car', function () {
        $first = extrasStudied()->crateValue[0];

        expect($first['rung'])->toBe('L0')
            ->and($first['crates'][0]['crate_days'])->toBeGreaterThan($first['step_days'])
            ->and($first['crates'][1]['crate_days'])->toBeGreaterThan($first['crates'][0]['crate_days'])
            ->and($first['programme_crates'])->toBe(150)
            ->and($first['programme_days'])->toBeGreaterThan($first['crates'][2]['crate_days']);
    });

    it('returns less than it costs from every kind of storage unit, so that buying and selling cannot make money', function () {
        $returns = extrasStudied()->unitReturns;

        expect(array_column($returns, 'kind'))->toBe(['small', 'medium', 'large'])
            ->and(array_column($returns, 'ok'))->toBe([true, true, true])
            ->and($returns[0]['mean_return'])->toBeBetween(0.89, 0.93)
            ->and($returns[1]['mean_return'])->toBeBetween(0.74, 0.78)
            ->and($returns[0]['chance_double'])->toBeBetween(0.08, 0.12);
    });

    it('passes as a whole: the shifts, the value of the crates and what the units return', function () {
        $result = extrasStudied();

        expect($result->passed())->toBeTrue()
            ->and($result->crateShiftPassed() && $result->crateValuePassed() && $result->unitShiftPassed() && $result->unitReturnsPassed())->toBeTrue();
    });
});
