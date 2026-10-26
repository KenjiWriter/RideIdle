<?php

declare(strict_types=1);

use App\Domain\Economy\EconomyRules;
use App\Domain\Economy\PaceGate;
use App\Domain\Economy\PlayerRun;
use App\Domain\Economy\Purchase;
use App\Domain\Economy\Simulator;

/** @return array<string, list<int>> the day of each purchase of the car of the ladder, by the kind of player, in the scenario game */
function gameDaysOf(EconomyRules $rules): array
{
    return array_map(
        static fn (PlayerRun $run): array => array_map(static fn (Purchase $purchase): int => $purchase->day, $run->purchases),
        (new Simulator)->simulateAllGame($rules),
    );
}

it('gives the days of the ladder for a fleet that the rules of the game allow: only the car that is played levels the driver, so the long game is the one of one car', function () {
    // Every car drives at once and earns, but the experience of the player is the one of the car that is played (`player.other_cars_xp_ppm` is 0),
    // so the wrecks in the garages do not make the levels, which bind the long game, come any sooner.
    expect(gameDaysOf(economyRules()))->toBe([
        'A' => [3, 8, 9, 18, 28, 36, 48, 69],
        'B' => [7, 23, 27, 61, 97, 121, 148, 185],
        'C' => [2, 3, 4, 7, 12, 17, 24, 37],
    ]);
});

it('ends with the garages full: fourteen wrecks, in the four garages of the other cities', function () {
    foreach ((new Simulator)->simulateAllGame(economyRules()) as $run) {
        expect($run->wrecks)->toBe(14)->and($run->extraGarages)->toBe(4);
    }
});

it('gives the same days every time it is played', function () {
    expect(gameDaysOf(economyRules()))->toBe(gameDaysOf(economyRules()));
});

it('stops at the last rung, and says how many days it took', function () {
    $run = (new Simulator)->simulateGame(economyRules(), economyRules()->players()['A']);

    expect($run->daysSimulated)->toBe(69)->and($run->dayOf(8))->toBe(69)->and($run->dayOf(9))->toBeNull();
});

it('leaves the designed scenario as it was: the fleet section changes nothing there', function () {
    $designed = fn (EconomyRules $rules): array => array_map(
        static fn (PlayerRun $run): array => array_map(static fn (Purchase $purchase): int => $purchase->day, $run->purchases),
        (new Simulator)->simulateAll($rules),
    );

    expect($designed(economyRules()))->toBe([
        'A' => [2, 6, 9, 17, 27, 35, 48, 69],
        'B' => [8, 21, 34, 64, 100, 124, 151, 188],
        'C' => [1, 2, 3, 6, 11, 16, 23, 36],
    ])->and($designed(economyRules(['model', 'fleet', 'wreck_price'], 1)))->toBe($designed(economyRules()));
});

it('does not use the fleet factor of the design: a car of the ladder is counted one at a time', function () {
    $factors = economyData();

    foreach (array_keys($factors['players']) as $key) {
        $factors['players'][$key]['fleet'] = array_fill(0, 9, 1.7);
    }

    expect(gameDaysOf(EconomyRules::fromArray($factors)))->toBe(gameDaysOf(economyRules()));
});

it('is within the pace gate for every kind of player with the numbers of the repository, and is out of it where the driver is levelled by every car', function () {
    $rules = economyRules();
    $report = (new PaceGate)->evaluate($rules, (new Simulator)->simulateAllGame($rules));

    expect($report->passed())->toBeTrue();

    // With every car giving the experience of its laps (what the game did before) the long game came in half the days of the design.
    $all = economyRules(['model', 'fleet', 'other_cars_xp_share'], 1.0);
    $outPlayers = array_values(array_unique(array_map(static fn ($row): string => $row->player, (new PaceGate)->evaluate($all, (new Simulator)->simulateAllGame($all))->failures())));

    expect($outPlayers)->toBe(['A', 'B', 'C']);
});

describe('the knobs of the fleet', function () {
    it('gives the experience of the others only by their share: with all of it, every car makes the levels and the long game comes in half the days', function () {
        $rules = economyRules(['model', 'fleet', 'other_cars_xp_share'], 1.0);

        // What the game did until the experience was the one of the car that is played: the wrecks in the garages are what makes the level come quickly.
        expect(gameDaysOf($rules))->toBe([
            'A' => [3, 8, 9, 10, 13, 16, 22, 36],
            'B' => [7, 23, 27, 33, 43, 54, 68, 98],
            'C' => [2, 3, 4, 4, 5, 6, 9, 15],
        ]);
    });

    it('gives half of the experience for half of the share, which is between the two', function () {
        $none = gameDaysOf(economyRules())['A'][7];
        $half = gameDaysOf(economyRules(['model', 'fleet', 'other_cars_xp_share'], 0.5))['A'][7];
        $all = gameDaysOf(economyRules(['model', 'fleet', 'other_cars_xp_share'], 1.0))['A'][7];

        expect($all)->toBeLessThan($half)->and($half)->toBeLessThan($none);
    });

    it('buys no wreck when there is no place for one and no city that sells a garage', function () {
        $data = economyData();
        $data['model']['fleet']['home_free_places'] = 0;
        $data['model']['fleet']['garage_city_levels'] = [];
        $rules = EconomyRules::fromArray($data);

        // With no wrecks the days are those of one car, with no fleet at all.
        expect(gameDaysOf($rules))->toBe([
            'A' => [2, 6, 10, 18, 28, 36, 48, 69],
            'B' => [8, 21, 34, 64, 100, 124, 151, 188],
            'C' => [1, 2, 3, 6, 11, 16, 23, 36],
        ]);

        foreach ((new Simulator)->simulateAllGame($rules) as $run) {
            expect($run->wrecks)->toBe(0)->and($run->extraGarages)->toBe(0);
        }
    });

    it('buys wrecks only in the home garage when no city sells a garage', function () {
        $rules = economyRules(['model', 'fleet', 'garage_city_levels'], []);

        foreach ((new Simulator)->simulateAllGame($rules) as $run) {
            expect($run->wrecks)->toBe(2)->and($run->extraGarages)->toBe(0);
        }
    });

    it('slows the fleet down when the garages cost more, and has fewer of them', function () {
        $cheap = (new Simulator)->simulateGame(economyRules(), economyRules()->players()['B']);
        $dear = (new Simulator)->simulateGame(economyRules(['model', 'fleet', 'garage_price'], 500_000), economyRules()->players()['B']);

        expect($dear->dayOf(8))->toBeGreaterThan($cheap->dayOf(8))
            ->and($dear->extraGarages)->toBeLessThan($cheap->extraGarages);
    });

    it('opens the garages with the level, so that a city that opens later has its garage later, and a city that is never opened has none', function () {
        $never = economyRules(['model', 'fleet', 'garage_city_levels'], [99, 99, 99, 99]);
        $early = economyRules(['model', 'fleet', 'garage_city_levels'], [1, 1, 1, 1]);

        expect((new Simulator)->simulateGame($early, $early->players()['B'])->extraGarages)->toBe(4)
            ->and((new Simulator)->simulateGame($never, $never->players()['B'])->extraGarages)->toBe(0);
    });

    it('lets the money decide when a wreck pays back later than a car of the ladder does', function () {
        // A wreck at a hundred times the price never pays back before the cars of the ladder do, so none is bought and the days are of one car.
        $rules = economyRules(['model', 'fleet', 'wreck_price'], 1_000_000);

        expect(gameDaysOf($rules)['A'])->toBe([2, 6, 10, 18, 28, 36, 48, 69]);
    });
});

it('says what a car earns in a day without the fleet factor, and the designed income is that with the factor', function () {
    $rules = economyRules();
    $regular = $rules->players()['A'];

    // The first two rungs of the design have a fleet of one car, so the incomes are the same there, and from the third rung the design counts 1.4 cars and more.
    expect($rules->carDailyIncome($regular, 0))->toBe($rules->dailyIncome($regular, 0))
        ->and($rules->carDailyIncome($regular, 1))->toBe($rules->dailyIncome($regular, 1))
        ->and($rules->dailyIncome($regular, 2))->toEqualWithDelta($rules->carDailyIncome($regular, 2) * 1.4, 1e-9)
        ->and($rules->dailyIncome($regular, 8))->toEqualWithDelta($rules->carDailyIncome($regular, 8) * 1.7, 1e-9);
});

it('reads the fleet of the game from the ruleset', function () {
    $fleet = economyRules()->fleetModel();

    expect($fleet->wreckPrice)->toBe(1140.0)
        ->and($fleet->garagePrice)->toBe(5000.0)
        ->and($fleet->garageCapacity)->toBe(3)
        ->and($fleet->homeFreePlaces)->toBe(2)
        ->and($fleet->garageCityLevels)->toBe([3, 5, 8, 12])
        ->and($fleet->otherCarsXpShare)->toBe(0.0)
        // The home garage and every garage that is bought make room for this many wrecks, and a level opens this many cities.
        ->and([$fleet->places(0), $fleet->places(1), $fleet->places(4)])->toBe([2, 5, 14])
        ->and([$fleet->garagesOpenAt(1), $fleet->garagesOpenAt(3), $fleet->garagesOpenAt(8), $fleet->garagesOpenAt(30)])->toBe([0, 1, 3, 4]);
});
