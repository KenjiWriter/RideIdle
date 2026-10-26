<?php

declare(strict_types=1);

use App\Domain\Economy\GameLadder;
use App\Domain\Economy\LadderRow;

/**
 * The income of a level of the game, as the collector hands it over.
 *
 * @return array{net: float, car: string, track: string}
 */
function ladderIncome(float $net, string $car = 'a-car', string $track = 'a-track'): array
{
    return ['net' => $net, 'car' => $car, 'track' => $track];
}

// The anchors: $600 an hour at the first rung, 15% more at each rung after it, and the rungs open at levels 1, 3, 6, 9, 12, 15, 18, 22 and 27.
describe('the levels of the ladder', function () {
    it('are the levels that open the rungs', function () {
        expect((new GameLadder)->levels(economyRules()))->toBe([1, 3, 6, 9, 12, 15, 18, 22, 27]);
    });
});

describe('the rows of the game ladder', function () {
    it('sets the income of a level against the anchor of the rung of that level', function () {
        $rows = (new GameLadder)->check(economyRules(), [1 => ladderIncome(600.0, 'wreck', 'street'), 6 => ladderIncome(700.0, 'coupe', 'drag')]);

        expect($rows)->toHaveCount(2)
            ->and($rows[0])->toBeInstanceOf(LadderRow::class)
            ->and($rows[0]->level)->toBe(1)
            ->and($rows[0]->rung)->toBe('L0')
            ->and($rows[0]->anchor)->toEqualWithDelta(600.0, 1e-9)
            ->and($rows[0]->game)->toBe(600.0)
            ->and($rows[0]->car)->toBe('wreck')
            ->and($rows[0]->track)->toBe('street')
            ->and($rows[0]->deviation())->toEqualWithDelta(0.0, 1e-9)
            ->and($rows[0]->ok)->toBeTrue()
            ->and($rows[1]->rung)->toBe('L2')
            ->and($rows[1]->anchor)->toEqualWithDelta(600 * 1.15 ** 2, 1e-9);
    });

    it('takes the rung of a level to be the highest rung that the level opens', function (int $level, string $rung) {
        $row = (new GameLadder)->check(economyRules(), [$level => ladderIncome(900.0)])[0];

        expect($row->rung)->toBe($rung);
    })->with([
        'a level below every rung is the first rung' => [0, 'L0'],
        'the first level' => [1, 'L0'],
        'a level between two rungs is the lower one' => [5, 'L1'],
        'the level that opens a rung' => [6, 'L2'],
        'the one before the next' => [8, 'L2'],
        'the last rung' => [27, 'L8'],
        'a level above the last rung' => [100, 'L8'],
    ]);

    it('puts the rows from the lowest level to the highest, whatever order the incomes come in', function () {
        $rows = (new GameLadder)->check(economyRules(), [12 => ladderIncome(900.0), 1 => ladderIncome(600.0), 6 => ladderIncome(800.0)]);

        expect(array_map(fn (LadderRow $row): int => $row->level, $rows))->toBe([1, 6, 12]);
    });

    it('says how far the game is from the anchor, negative when the game pays less', function () {
        $less = (new GameLadder)->check(economyRules(), [3 => ladderIncome(600.0)])[0];
        $more = (new GameLadder)->check(economyRules(), [3 => ladderIncome(759.0)])[0];

        // The anchor of the second rung is $690.
        expect($less->deviation())->toEqualWithDelta(-0.1304, 1e-4)
            ->and($more->deviation())->toEqualWithDelta(0.1, 1e-9);
    });

    it('is in order within the tolerance of the consistency, either way, and out of it beyond', function () {
        // The tolerance is 20%, and the anchor of the third rung is $793.50: from $634.80 to $952.20.
        $check = fn (float $net): bool => (new GameLadder)->check(economyRules(), [6 => ladderIncome($net)])[0]->ok;

        expect($check(793.5))->toBeTrue()
            ->and($check(640.0))->toBeTrue()
            ->and($check(950.0))->toBeTrue()
            ->and($check(630.0))->toBeFalse()
            ->and($check(960.0))->toBeFalse();
    });

    it('follows the tolerance of the ruleset', function () {
        $rules = economyRules(['consistency', 'tolerance'], 0.05);

        expect((new GameLadder)->check($rules, [6 => ladderIncome(700.0)])[0]->ok)->toBeFalse()
            ->and((new GameLadder)->check($rules, [6 => ladderIncome(790.0)])[0]->ok)->toBeTrue();
    });

    it('has no rows for no incomes', function () {
        expect((new GameLadder)->check(economyRules(), []))->toBe([]);
    });
});

describe('the rungs that nothing in the game reaches', function () {
    it('are the ones whose anchor is above the best income of the game at any level', function () {
        // $984 an hour is above the anchors of the first four rungs ($600, $690, $793.50 and $912.50) and below the fifth ($1,049.40).
        $incomes = [1 => ladderIncome(600.0), 12 => ladderIncome(984.0)];

        expect((new GameLadder)->unreachableRungs(economyRules(), $incomes))->toBe([4 => 'L4', 5 => 'L5', 6 => 'L6', 7 => 'L7', 8 => 'L8']);
    });

    it('counts the best income of any level, not the one of the last', function () {
        $incomes = [1 => ladderIncome(1_100.0), 12 => ladderIncome(900.0)];

        expect((new GameLadder)->unreachableRungs(economyRules(), $incomes))->toBe([5 => 'L5', 6 => 'L6', 7 => 'L7', 8 => 'L8']);
    });

    it('lets a rung count as reached when the game gets within a tolerance of it', function () {
        $incomes = [12 => ladderIncome(984.0)];

        // 80% of the anchors of the fifth and the sixth rungs ($965.40 and $1,110.20) are above $984 only from the sixth on.
        expect((new GameLadder)->unreachableRungs(economyRules(), $incomes, 0.2))->toBe([6 => 'L6', 7 => 'L7', 8 => 'L8']);
    });

    it('is none when the game pays at least what the last rung does', function () {
        expect((new GameLadder)->unreachableRungs(economyRules(), [27 => ladderIncome(1_900.0)]))->toBe([]);
    });

    it('is every rung when the game pays nothing', function () {
        expect(array_keys((new GameLadder)->unreachableRungs(economyRules(), [])))->toBe(range(0, 8));
    });
});
