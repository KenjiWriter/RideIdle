<?php

declare(strict_types=1);

use App\Content\GameUnits;
use App\Domain\Economy\PlayerRun;
use App\Domain\Economy\Purchase;
use App\Domain\Economy\Simulator;
use App\Domain\Economy\UnitExtra;

function unitReturnsTable(): array
{
    return json_decode((string) file_get_contents(app(GameUnits::class)->path()), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, list<int>> the days of the purchases of the ladder with the real table of the units at this share, by kind of player */
function unitReturnsDays(float $share): array
{
    $units = app(GameUnits::class)->economy();

    return array_map(
        static fn (PlayerRun $run): array => array_map(static fn (Purchase $purchase): int => $purchase->day, $run->purchases),
        (new Simulator)->simulateAllGame(economyRules(), static fn (): array => [new UnitExtra($share, $units)]),
    );
}

describe('the table of what the storage units pay back', function () {
    it('is what the generator computes now: the table of the file is up to date', function () {
        // The same seeds and the same trials as the file says, so it is the table to the last digit. It takes a couple of seconds.
        $units = app(GameUnits::class);

        expect(file_get_contents($units->path()))->toBe($units->computedJson(unitReturnsTable()['trials']));
    });

    it('says how it was made: the trials, the seeds, the guarantee and the rule of the sale', function () {
        $table = unitReturnsTable();

        expect($table['trials'])->toBe(GameUnits::TRIALS)
            ->and($table['seed_prefix'])->toBe('unit-returns')
            ->and($table['pity_every'])->toBe(15)
            ->and($table['resale_ppm'])->toBe(700_000)
            ->and($table['scrap_cents_per_kg'])->toBe(25)
            ->and(array_keys($table['units']))->toBe(['small', 'medium', 'large'])
            ->and($table['percentiles'])->toHaveCount(103)
            ->and($table['percentiles'][0])->toBe(0)->and(end($table['percentiles']))->toBe(100);
    });

    it('has the prices of the rules, and a sale for every percentile, rising', function () {
        $table = unitReturnsTable();

        expect(array_column($table['units'], 'price'))->toBe([1_200, 3_500, 6_000]);

        foreach ($table['units'] as $kind => $row) {
            $sales = $row['sale_dollars'];

            expect($sales)->toHaveCount(count($table['percentiles']), $kind)
                ->and($sales)->toBe(collect($sales)->sort()->values()->all())
                // The least a car is bought for is the scrap of its mass: 250 dollars for a car of a thousand kilos.
                ->and($sales[0])->toBeGreaterThan(200.0);
        }
    });

    it('agrees with the experiment that the numbers of the design were taken from: 91%, 76% and 77% mean return, within a point', function () {
        $units = unitReturnsTable()['units'];

        expect($units['small']['mean_return'])->toEqualWithDelta(0.91, 0.01)
            ->and($units['medium']['mean_return'])->toEqualWithDelta(0.76, 0.01)
            ->and($units['large']['mean_return'])->toEqualWithDelta(0.77, 0.01)
            // The medians: a unit is a lottery, and most of the finds are sold for less than the unit cost.
            ->and($units['small']['sale_dollars'][50] / 1_200)->toEqualWithDelta(0.49, 0.02)
            ->and($units['medium']['sale_dollars'][50] / 3_500)->toEqualWithDelta(0.39, 0.02)
            ->and($units['large']['sale_dollars'][50] / 6_000)->toEqualWithDelta(0.56, 0.02);
    });

    it('pays back less than the price of a unit on average, for every kind, with the guarantee and without it: no loop of buying and selling makes money', function () {
        foreach (unitReturnsTable()['units'] as $kind => $row) {
            expect($row['mean_return'])->toBeLessThan(1.0)
                ->and($row['mean_return_without_guarantee'])->toBeLessThan(1.0);
        }
    });

    it('shows that the guarantee is what lifts the small unit: from about 77% to about 91%, and the others hardly at all', function () {
        $units = unitReturnsTable()['units'];

        expect($units['small']['mean_return'] - $units['small']['mean_return_without_guarantee'])->toBeGreaterThan(0.1)
            ->and(abs($units['medium']['mean_return'] - $units['medium']['mean_return_without_guarantee']))->toBeLessThan(0.02)
            ->and(abs($units['large']['mean_return'] - $units['large']['mean_return_without_guarantee']))->toBeLessThan(0.02);
    });
});

describe('the economy of the storage units that the simulator reads', function () {
    it('has the kinds, the levels and the prices of the rules, and the returns of the table', function () {
        $economy = app(GameUnits::class)->economy();

        expect($economy->kinds())->toBe(['small', 'medium', 'large'])
            ->and(array_map(fn (string $kind): int => $economy->unlockLevel($kind), $economy->kinds()))->toBe([3, 6, 9])
            ->and(array_map(fn (string $kind): float => $economy->price($kind), $economy->kinds()))->toBe([1_200.0, 3_500.0, 6_000.0])
            ->and($economy->meanReturn('small'))->toEqualWithDelta(0.91, 0.01);
    });

    it('gives the chance of a jackpot from the table: about two in a hundred small units are sold for five times their price', function () {
        $economy = app(GameUnits::class)->economy();

        expect($economy->chanceOfAtLeast('small', 6_000.0))->toBeBetween(0.01, 0.04)
            ->and($economy->chanceOfAtLeast('small', 1_200.0))->toBeBetween(0.15, 0.3)
            ->and($economy->chanceOfAtLeast('small', 100_000.0))->toBe(0.0)
            ->and($economy->chanceOfAtLeast('large', 6_000.0))->toBeBetween(0.2, 0.4)
            ->and($economy->chanceOfAtLeast('large', 20_000.0))->toBeLessThan(0.02);
    });

    it('is not made without the table, and says how to make it', function () {
        // The rules and the pack are read from the real folder before the path of the tables is moved to a folder that has none of them.
        $units = app(GameUnits::class);
        $directory = sys_get_temp_dir().'/unit-returns-'.bin2hex(random_bytes(4));
        mkdir($directory);
        config(['ride.content_path' => $directory]);

        try {
            expect(fn () => $units->economy())->toThrow(InvalidArgumentException::class, 'economy:unit-returns');
        } finally {
            rmdir($directory);
        }
    });
});

describe('the scenario game with the storage units', function () {
    it('costs a few percent of the income of the car at 10%, 20% and 30% of it put into units, and the money that comes back is less than the money that went', function () {
        $units = app(GameUnits::class)->economy();

        foreach (economyRules()->players() as $key => $player) {
            $net = [];

            foreach ([0.1, 0.2, 0.3] as $share) {
                $note = (new Simulator)->simulateGame(economyRules(), $player, [new UnitExtra($share, $units)])->notes['units'];

                expect($note['returned'])->toBeLessThan($note['spent'])
                    ->and($note['net_share_of_income'])->toBeGreaterThan(0.0)->toBeLessThan(0.1);

                $net[] = $note['net_cost'];
            }

            expect($net[1])->toBeGreaterThan($net[0])->and($net[2])->toBeGreaterThan($net[1]);
        }
    });

    it('moves the last purchase of the ladder by three days at most at any of the shares, and never brings one sooner', function () {
        $none = (new Simulator)->simulateAllGame(economyRules());

        foreach ([0.1, 0.2, 0.3] as $share) {
            foreach (unitReturnsDays($share) as $key => $days) {
                foreach ($days as $index => $day) {
                    expect($day)->toBeGreaterThanOrEqual($none[$key]->purchases[$index]->day);
                }

                expect(end($days) - $none[$key]->purchases[7]->day)->toBeBetween(0, 3);
            }
        }
    });

    it('gives the days that the numbers of the report were taken from', function () {
        expect(unitReturnsDays(0.3))->toBe([
            'A' => [3, 8, 9, 10, 13, 16, 22, 37],
            'B' => [7, 24, 28, 34, 44, 55, 69, 101],
            'C' => [2, 3, 4, 4, 5, 6, 9, 15],
        ]);
    });

    it('hardly ever has a find pay for a whole step of the ladder, because the steps are dear when the units open', function () {
        $units = app(GameUnits::class)->economy();

        foreach (economyRules()->players() as $key => $player) {
            $note = (new Simulator)->simulateGame(economyRules(), $player, [new UnitExtra(0.3, $units)])->notes['units'];

            expect($note['expected_jumps'])->toBeLessThan(0.5)->and($note['expected_jump_days'])->toBeLessThan(1.0);
        }
    });
});
