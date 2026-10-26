<?php

declare(strict_types=1);

use App\Content\GameCrates;
use App\Domain\Economy\CrateExtra;
use App\Domain\Economy\CratePayback;
use App\Domain\Economy\PlayerRun;
use App\Domain\Economy\Purchase;
use App\Domain\Economy\Simulator;

/**
 * The scenario game with the crates of the game, for every kind of player, at a share of the income.
 *
 * @return array<string, PlayerRun>
 */
function gameWithCrates(float $share): array
{
    $economy = app(GameCrates::class)->economy();

    return (new Simulator)->simulateAllGame(economyRules(), static fn (): array => [new CrateExtra($share, $economy)]);
}

/** @return array<string, list<int>> the days of the purchases of the ladder, by the kind of player */
function daysWithCrates(float $share): array
{
    return array_map(
        static fn (PlayerRun $run): array => array_map(static fn (Purchase $purchase): int => $purchase->day, $run->purchases),
        gameWithCrates($share),
    );
}

describe('the scenario game with the crates of the game', function () {
    it('is the scenario without the crates when nothing goes into them', function () {
        expect(daysWithCrates(0.0))->toBe([
            'A' => [3, 8, 9, 10, 13, 16, 22, 36],
            'B' => [7, 23, 27, 33, 43, 54, 68, 98],
            'C' => [2, 3, 4, 4, 5, 6, 9, 15],
        ]);
    });

    it('gives the days for a tenth, a fifth and a third of the income spent on crates: the pace slows by a day or two, and by a few at the end', function () {
        // What the sweep of the ruleset shows today. The crates cost more than the income they add, so the purchases do not come sooner:
        // L8 comes on day 37, 38 and 40 for the regular player where it came on 36, and on 100, 105 and 111 for the occasional one (98). The crates of the
        // tier 8 cost 9% more than the anchors say (`tier_price_ppm`), so a third of the income buys a few fewer of them and ends a day sooner than before.
        expect(daysWithCrates(0.1))->toBe([
            'A' => [3, 8, 9, 10, 13, 16, 22, 37],
            'B' => [7, 23, 27, 33, 43, 54, 68, 100],
            'C' => [2, 3, 4, 4, 5, 6, 9, 16],
        ])->and(daysWithCrates(0.2))->toBe([
            'A' => [3, 8, 10, 11, 13, 17, 23, 38],
            'B' => [7, 24, 28, 34, 44, 55, 71, 105],
            'C' => [2, 4, 4, 5, 5, 7, 9, 16],
        ])->and(daysWithCrates(0.3))->toBe([
            'A' => [3, 8, 9, 11, 14, 17, 24, 40],
            'B' => [7, 25, 29, 35, 44, 55, 74, 111],
            'C' => [2, 4, 4, 5, 5, 7, 10, 16],
        ]);
    });

    it('opens more crates and spends more the larger the share of the income, for every kind of player', function () {
        $tenth = gameWithCrates(0.1);
        $fifth = gameWithCrates(0.2);
        $third = gameWithCrates(0.3);

        foreach (['A', 'B', 'C'] as $key) {
            expect($fifth[$key]->notes['crates']['crates'])->toBeGreaterThan($tenth[$key]->notes['crates']['crates'])
                ->and($third[$key]->notes['crates']['crates'])->toBeGreaterThan($fifth[$key]->notes['crates']['crates'])
                ->and($third[$key]->notes['crates']['spent'])->toBeGreaterThan($tenth[$key]->notes['crates']['spent']);
        }
    });

    it('says in the report of the run what the crates were: the share, the number, the money, the gain and the tier', function () {
        $report = gameWithCrates(0.2)['A']->notes['crates'];

        expect($report)->toMatchArray(['share' => 0.2, 'crates' => 18, 'tier' => 10, 'medians' => ['rare' => 5, 'epic' => 18, 'legendary' => 150]])
            ->and($report['spent'])->toBeGreaterThan(100_000)
            // The anchors price the tiers up to the tenth, and the crates of the game are of the first three.
            ->and($report['priced_by'])->toContain('tiers 1 to 10');
    });

    it('lets the player reach the first rare part on a day that the report names, and the epic and legendary ones rarely or never', function () {
        $report = gameWithCrates(0.3)['A']->notes['crates'];

        // The crates of a tier begin again at every tier, and the player is in the tenth for the last days: no epic or legendary part is reached.
        expect($report['first_rare_day'])->toBe(12)
            ->and($report['first_epic_day'])->toBeNull()
            ->and($report['first_legendary_day'])->toBeNull();
    });

    it('is a worse deal than a step of the ladder at the start, and a better one from the fourth rung on', function () {
        $rules = economyRules();
        $economy = app(GameCrates::class)->economy();
        $payback = new CratePayback;
        $player = $rules->players()['A'];
        $row = fn (int $rung, int $level): array => $payback->compare($rules, $player, $economy, $rung, $level, [0])[0];

        // Days to pay back, for the first crate of the tier at the level where the rung is bought: a crate takes 19.6 days, and the first step 2.4.
        expect($row(0, 1)['crate_days'])->toBeGreaterThan($row(0, 1)['step_days'] * 5)
            ->and($row(2, 6)['crate_days'])->toBeGreaterThan($row(2, 6)['step_days'])
            ->and($row(4, 12)['crate_days'])->toBeLessThan($row(4, 12)['step_days'])
            ->and($row(5, 15)['crate_days'])->toBeLessThan($row(5, 15)['step_days'] / 5);
    });
});
