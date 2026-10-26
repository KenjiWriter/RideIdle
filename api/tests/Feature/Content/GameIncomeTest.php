<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\GameIncome;
use App\Content\Rules;
use App\Domain\Economy\GameLadder;

function gameIncome(): GameIncome
{
    return app(GameIncome::class);
}

// The numbers below were measured on the pack as it is (ten cars, five tracks, the stock parts) and pin it: when the pack or the rules change them,
// the game ladder has changed, and the report of `economy:simulate` has to be read again.
describe('the income of a car on a track', function () {
    it('is $600 an hour for the stock Rdzak GT-4 on the first track, as the anchor of the first rung says', function () {
        expect(gameIncome()->netPerHour('rdzak-gt-4', 'zlomowo-street'))->toEqualWithDelta(600.0, 0.5);
    });

    it('is the laps of an hour times the reward of a lap less the fuel of it, at the price of the rules', function () {
        // 48 laps of 75 s pay $15.50 each, less 1.5 l of fuel at $2.
        expect(gameIncome()->netPerHour('rdzak-gt-4', 'zlomowo-street'))->toEqualWithDelta(48 * (15.5 - 1.5 * 2), 0.5);
    });
});

describe('what a level has to choose from', function () {
    it('are the wrecks of the yard and the cheapest car for sale at the first level, on the first track', function () {
        expect(gameIncome()->carsAt(1))->toBe(['bizon-m-2', 'kuna-4x4', 'rdzak-gt-2', 'rdzak-gt-4'])
            ->and(gameIncome()->tracksAt(1))->toBe(['zlomowo-street']);
    });

    it('grow with the level: the cars that ask it, and the tracks that open', function () {
        expect(gameIncome()->carsAt(3))->toBe(['bizon-m-2', 'furgon-z-1', 'kuna-4x4', 'rdzak-gt-2', 'rdzak-gt-3', 'rdzak-gt-4'])
            ->and(gameIncome()->carsAt(9))->toHaveCount(10)
            ->and(gameIncome()->tracksAt(5))->toBe(['zlomowo-street', 'kruszyn-quarry', 'suchy-pas-drag'])
            ->and(gameIncome()->tracksAt(12))->toHaveCount(5);
    });

    it('leaves out a car that is not for sale anywhere, and every car that is not a combustion one', function () {
        $rules = app(Rules::class);

        // Every car of the pack that is offered to a driver is either for sale or a wreck of the yard.
        foreach (gameIncome()->carsAt(100) as $car) {
            $vehicle = app(Catalog::class)->vehicle($car);

            expect($vehicle['powertrain'])->toBe('ice')
                ->and(array_intersect($vehicle['sources'], ['dealer', 'exchange']) !== [] || $rules->wreckPriceCents($car) !== null)->toBeTrue();
        }
    });
});

describe('the income by level', function () {
    it('is asked for at the levels where a car or a track opens, the first one, and the ones that are named', function () {
        expect(array_keys(gameIncome()->byLevel()))->toBe([1, 3, 5, 6, 8, 9, 12])
            ->and(array_keys(gameIncome()->byLevel([27, 15, 1])))->toBe([1, 3, 5, 6, 8, 9, 12, 15, 27]);
    });

    it('is a wreck on the first track at the first level, around $600 an hour', function () {
        $first = gameIncome()->byLevel()[1];

        expect($first['car'])->toBe('rdzak-gt-4')
            ->and($first['track'])->toBe('zlomowo-street')
            ->and($first['net'])->toEqualWithDelta(600.0, 0.5);
    });

    it('never falls as the level rises, because what a level opens is added to what the lower ones had', function () {
        $incomes = array_column(gameIncome()->byLevel(range(1, 30)), 'net');

        for ($i = 1; $i < count($incomes); $i++) {
            expect($incomes[$i])->toBeGreaterThanOrEqual($incomes[$i - 1]);
        }
    });

    it('rises only when a track opens: no car that is bought pays more than a wreck of the yard on the same track', function () {
        $income = gameIncome();
        $rules = app(Rules::class);

        foreach (['zlomowo-street', 'kruszyn-quarry', 'suchy-pas-drag', 'wilcza-przelecz-switchbacks', 'autodrom-circuit'] as $track) {
            $best = collect($income->carsAt(100))->map(fn (string $car): float => $income->netPerHour($car, $track))->max();
            $wreck = collect($income->carsAt(100))->filter(fn (string $car): bool => $rules->wreckPriceCents($car) !== null)->map(fn (string $car): float => $income->netPerHour($car, $track))->max();

            // The best car of the pack is within 2% of the best wreck on every track: what a car costs does not change what an hour of driving pays.
            expect($best / $wreck)->toBeLessThan(1.02);
        }
    });

    it('pays about $984 an hour at the top, on the Autodrom', function () {
        $top = collect(gameIncome()->byLevel())->last();

        expect($top['track'])->toBe('autodrom-circuit')
            ->and($top['net'])->toEqualWithDelta(984.0, 1.0);
    });

    it('is the one of the track that has just opened at the levels where a track opens', function () {
        $incomes = gameIncome()->byLevel();

        expect($incomes[5]['track'])->toBe('suchy-pas-drag')
            ->and($incomes[8]['track'])->toBe('wilcza-przelecz-switchbacks')
            ->and($incomes[12]['track'])->toBe('autodrom-circuit');
    });
});

describe('the game ladder of the pack', function () {
    it('is within the tolerance of the anchors up to the fifth rung, and out of it from the seventh on', function () {
        $economy = economyRules();
        $ladder = new GameLadder;
        $rows = collect($ladder->check($economy, gameIncome()->byLevel($ladder->levels($economy))))->keyBy('level');

        // The deviations of the game from the anchors at the levels of the rungs: 0, -13, -7, -8, -6, -18, -29, -38 and -46 per cent.
        expect($rows[1]->deviation())->toEqualWithDelta(0.0, 0.01)
            ->and($rows[3]->deviation())->toEqualWithDelta(-0.13, 0.01)
            ->and($rows[12]->deviation())->toEqualWithDelta(-0.062, 0.01)
            ->and($rows[18]->deviation())->toEqualWithDelta(-0.291, 0.01)
            ->and($rows[27]->deviation())->toEqualWithDelta(-0.464, 0.01)
            ->and(array_map(fn ($row): bool => $row->ok, [$rows[1], $rows[3], $rows[6], $rows[9], $rows[12], $rows[15]]))->toBe([true, true, true, true, true, true])
            ->and(array_map(fn ($row): bool => $row->ok, [$rows[18], $rows[22], $rows[27]]))->toBe([false, false, false]);
    });

    it('has no car and track that reach the rungs from the fifth on, and from the seventh when the tolerance is allowed', function () {
        $economy = economyRules();
        $ladder = new GameLadder;
        $incomes = gameIncome()->byLevel($ladder->levels($economy));

        expect($ladder->unreachableRungs($economy, $incomes))->toBe([4 => 'L4', 5 => 'L5', 6 => 'L6', 7 => 'L7', 8 => 'L8'])
            ->and($ladder->unreachableRungs($economy, $incomes, $economy->consistencyTolerance()))->toBe([6 => 'L6', 7 => 'L7', 8 => 'L8']);
    });
});
