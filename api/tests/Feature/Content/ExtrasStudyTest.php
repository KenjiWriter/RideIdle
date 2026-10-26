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

    it('says that a crate pays back sooner than the next car from the rung where the cars become dear: the gate of the value of a crate is out', function () {
        $result = extrasStudied();
        $sooner = array_values(array_map(static fn (array $row): string => $row['rung'], array_filter($result->crateValue, static fn (array $row): bool => $row['crate_pays_back_sooner'])));

        expect($result->crateValuePassed())->toBeFalse()
            ->and($sooner)->toContain('L4', 'L5')
            ->and($sooner)->not->toContain('L0', 'L1', 'L2', 'L3')
            ->and($result->valuedPlayer)->toBe('A');
    });

    it('gives a crate of the first rung a payback in days, set against the days of the next car', function () {
        $first = extrasStudied()->crateValue[0];

        expect($first['rung'])->toBe('L0')
            ->and($first['crates'][0]['crate_days'])->toBeGreaterThan($first['step_days'])
            ->and($first['crates'][1]['crate_days'])->toBeGreaterThan($first['crates'][0]['crate_days']);
    });

    it('returns less than it costs from every kind of storage unit, so that buying and selling cannot make money', function () {
        $returns = extrasStudied()->unitReturns;

        expect(array_column($returns, 'kind'))->toBe(['small', 'medium', 'large'])
            ->and(array_column($returns, 'ok'))->toBe([true, true, true])
            ->and($returns[0]['mean_return'])->toBeBetween(0.89, 0.93)
            ->and($returns[1]['mean_return'])->toBeBetween(0.74, 0.78)
            ->and($returns[0]['chance_double'])->toBeBetween(0.08, 0.12);
    });

    it('fails as a whole while the crates pay back sooner than a car, and passes when only the gates that hold are asked', function () {
        $result = extrasStudied();

        expect($result->passed())->toBeFalse()
            ->and($result->crateShiftPassed() && $result->unitShiftPassed() && $result->unitReturnsPassed())->toBeTrue();
    });
});
