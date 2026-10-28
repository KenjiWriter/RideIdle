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

// The numbers below were measured on the pack as it is (23 combustion cars, five tracks, the stock parts) and pin it: when the pack or the rules change them,
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

describe('the experience an hour of a car on a track', function () {
    it('is the laps of an hour times the experience of a lap: 48 laps of 20 XP for the stock Rdzak GT-4 on the first track', function () {
        expect(gameIncome()->xpPerHour('rdzak-gt-4', 'zlomowo-street'))->toEqualWithDelta(48 * 20, 0.5);
    });

    it('does not grow with the speed of the car: the Orzeł V12 on the Autodrom is paid the share of the base lap that it takes, like the stock Rdzak', function () {
        // 54.1 laps of 17 XP (16.6 rounded) against 42.2 laps of 20 XP: the faster laps pay less, so the hour is about the same, and about the 900 that the track
        // sets (the rounding of a lap is 2% of it).
        expect(gameIncome()->xpPerHour('orzel-v12', 'autodrom-circuit'))->toEqualWithDelta(920.4, 3.0)
            ->and(gameIncome()->xpPerHour('rdzak-gt-4', 'autodrom-circuit'))->toEqualWithDelta(845.0, 3.0);
    });
});

describe('what a level has to choose from', function () {
    it('are the wrecks of the yard and the cheapest car for sale at the first level, on the first track', function () {
        expect(gameIncome()->carsAt(1))->toBe(['bizon-m-2', 'kuna-4x4', 'rdzak-gt-2', 'rdzak-gt-4'])
            ->and(gameIncome()->tracksAt(1))->toBe(['zlomowo-street', 'zlomowo-yard-dash']);
    });

    it('grow with the level: the cars that ask it, and the tracks that open', function () {
        expect(gameIncome()->carsAt(3))->toBe(['bizon-m-2', 'furgon-z-1', 'kuna-4x4', 'rdzak-gt-2', 'rdzak-gt-3', 'rdzak-gt-4'])
            ->and(gameIncome()->carsAt(9))->toHaveCount(10)
            // The cars above the fourth tier ask level 12 and more.
            ->and(gameIncome()->carsAt(100))->toHaveCount(23)
            ->and(gameIncome()->tracksAt(5))->toBe(['zlomowo-street', 'kruszyn-quarry', 'suchy-pas-drag', 'zlomowo-yard-dash', 'zlomowo-ring-road', 'kruszyn-gravel-sprint', 'suchy-pas-short-strip'])
            // The five standard tracks, the five sprints and the endurance tracks of the first four cities (they ask level 4, 6, 8 and 11); the endurance of the Autodrom asks level 15.
            ->and(gameIncome()->tracksAt(12))->toHaveCount(14)
            ->and(gameIncome()->tracksAt(15))->toHaveCount(15);
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
        expect(array_keys(gameIncome()->byLevel()))->toBe([1, 3, 4, 5, 6, 8, 9, 11, 12, 15, 18, 21, 27])
            ->and(array_keys(gameIncome()->byLevel([22, 15, 1])))->toBe([1, 3, 4, 5, 6, 8, 9, 11, 12, 15, 18, 21, 22, 27]);
    });

    it('is a wreck on the first track at the first level, around $600 an hour', function () {
        $first = gameIncome()->byLevel()[1];

        expect($first['car'])->toBe('rdzak-gt-4')
            ->and($first['track'])->toBe('zlomowo-street')
            ->and($first['net'])->toEqualWithDelta(600.0, 0.5);
    });

    it('carries the experience an hour of the best pair, within a fifth of the 900 an hour of the model at every level', function () {
        foreach (gameIncome()->byLevel(range(1, 30)) as $income) {
            expect($income['xp'])->toBeGreaterThan(720.0)->toBeLessThan(1_080.0);
        }
    });

    it('never falls as the level rises, because what a level opens is added to what the lower ones had', function () {
        $incomes = array_column(gameIncome()->byLevel(range(1, 30)), 'net');

        for ($i = 1; $i < count($incomes); $i++) {
            expect($incomes[$i])->toBeGreaterThanOrEqual($incomes[$i - 1]);
        }
    });

    it('rises with the tracks for the cars of the first four tiers: none of them pays more than a wreck of the yard on the same track', function () {
        $income = gameIncome();
        $rules = app(Rules::class);
        $catalog = app(Catalog::class);
        $cheap = collect($income->carsAt(100))->filter(fn (string $car): bool => $catalog->vehicle($car)['tier'] <= 4);

        foreach (['zlomowo-street', 'kruszyn-quarry', 'suchy-pas-drag', 'wilcza-przelecz-switchbacks', 'autodrom-circuit'] as $track) {
            $best = $cheap->map(fn (string $car): float => $income->netPerHour($car, $track))->max();
            $wreck = $cheap->filter(fn (string $car): bool => $rules->wreckPriceCents($car) !== null)->map(fn (string $car): float => $income->netPerHour($car, $track))->max();

            // These cars are within 2% of the best wreck on every track: what they cost does not change what an hour of driving pays.
            expect($best / $wreck)->toBeLessThan(1.02);
        }
    });

    it('rises with the tier of the car from the fifth on: the best car of a tier pays about what the rung of the tier asks on the Autodrom', function () {
        $income = gameIncome();
        $economy = economyRules();
        $catalog = app(Catalog::class);

        // Tier t is the rung t - 1 of the ladder, and the last tier of the pack, the tenth, is the last rung.
        foreach ([5 => 4, 6 => 5, 7 => 6, 8 => 7, 10 => 8] as $tier => $rung) {
            $best = collect($income->carsAt(100))
                ->filter(fn (string $car): bool => $catalog->vehicle($car)['tier'] === $tier)
                ->map(fn (string $car): float => $income->netPerHour($car, 'autodrom-circuit'))
                ->max();

            // The best car of the fifth tier, the rear wheel drive Kornet R6, is 11.5% over its rung since the drives; the others are within 9.2%.
            expect(abs($best - $economy->netPerHour($rung)) / $economy->netPerHour($rung))->toBeLessThan(0.12);
        }
    });

    it('pays about $1 858 an hour at the top, the Orzeł V12 on the Autodrom, and more for every dearer car on the way up', function () {
        $top = collect(gameIncome()->byLevel())->last();

        expect($top['car'])->toBe('orzel-v12')
            ->and($top['track'])->toBe('autodrom-circuit')
            ->and($top['net'])->toEqualWithDelta(1857.5, 2.0)
            ->and(gameIncome()->netPerHour('serwal-gt', 'autodrom-circuit'))->toBeGreaterThan(gameIncome()->netPerHour('rdzak-gt-4', 'autodrom-circuit'));
    });

    it('is the one of the track that has just opened at the levels where a track opens', function () {
        $incomes = gameIncome()->byLevel();

        expect($incomes[5]['track'])->toBe('suchy-pas-drag')
            ->and($incomes[8]['track'])->toBe('wilcza-przelecz-switchbacks')
            ->and($incomes[12]['track'])->toBe('autodrom-circuit');
    });
});

describe('the game ladder of the pack', function () {
    it('is within the tolerance of the anchors at every level, the rungs from the fifth on included', function () {
        $economy = economyRules();
        $ladder = new GameLadder;
        $rows = collect($ladder->check($economy, gameIncome()->byLevel($ladder->levels($economy))))->keyBy('level');

        // The deviations of the game from the anchors at the levels of the rungs: 0, -13, -2, -8, +12, +4, +9, +1 and +1 per cent (and +16 at level 21, where
        // the Serwal V10 opens a level before its rung does). With the drives the rear wheel drive cars are quicker than the front wheel drive ones of their
        // tier, so at the fifth rung the best car is the Kornet R6 again (it burns less than the Bizon V8, and the two were level on the Autodrom before).
        expect($rows[1]->deviation())->toEqualWithDelta(0.0, 0.01)
            ->and($rows[3]->deviation())->toEqualWithDelta(-0.13, 0.01)
            ->and($rows[12]->deviation())->toEqualWithDelta(0.115, 0.01)
            ->and($rows[15]->deviation())->toEqualWithDelta(0.039, 0.01)
            ->and($rows[18]->deviation())->toEqualWithDelta(0.092, 0.01)
            ->and($rows[21]->deviation())->toEqualWithDelta(0.164, 0.01)
            ->and($rows[27]->deviation())->toEqualWithDelta(0.012, 0.01)
            ->and($rows->every(fn ($row): bool => $row->ok))->toBeTrue();
    });

    it('has a car and a track for every rung of the ladder when the tolerance is allowed', function () {
        $economy = economyRules();
        $ladder = new GameLadder;
        $incomes = gameIncome()->byLevel($ladder->levels($economy));

        expect($ladder->unreachableRungs($economy, $incomes, $economy->consistencyTolerance()))->toBe([])
            // Asked for all of the anchor, the Orzeł V12 is a dollar short of the last rung and nothing else is.
            ->and(array_diff(array_keys($ladder->unreachableRungs($economy, $incomes)), [8]))->toBe([]);
    });
});
