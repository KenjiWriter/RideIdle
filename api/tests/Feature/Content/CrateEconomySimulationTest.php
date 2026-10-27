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
            'A' => [3, 6, 7, 16, 26, 34, 46, 68],
            'B' => [8, 17, 21, 56, 92, 116, 143, 180],
            'C' => [2, 3, 3, 6, 11, 16, 24, 36],
        ]);
    });

    it('gives the days for a tenth, a fifth and a third of the income spent on crates: the long game does not move, and the early days of the others move by a day or two', function () {
        // What the sweep of the ruleset shows today. The long game is bound by the level (only the car that is played levels the driver), so the
        // money that the crates take was not going to buy a car sooner: the regular and the intensive player buy the same cars on the same days, and the
        // occasional one, whose money binds the first purchases, is a day or two later at a fifth and a third of the income.
        expect(daysWithCrates(0.1))->toBe(daysWithCrates(0.0))
            ->and(daysWithCrates(0.2))->toBe([
                'A' => [3, 6, 7, 16, 26, 34, 46, 68],
                'B' => [8, 18, 21, 56, 92, 116, 143, 180],
                'C' => [2, 3, 3, 6, 11, 16, 24, 36],
            ])->and(daysWithCrates(0.3))->toBe([
                'A' => [3, 6, 7, 16, 26, 34, 46, 68],
                'B' => [8, 18, 22, 56, 92, 116, 143, 180],
                'C' => [2, 3, 3, 6, 11, 16, 24, 36],
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

        // The player is level-bound, so the money that the crates do not take piles up, and a fifth of the income opens a lot of them (fewer than before the
        // place among the bots, because the crates of the high tiers cost more).
        expect($report)->toMatchArray(['share' => 0.2, 'crates' => 47, 'tier' => 10, 'medians' => ['rare' => 5, 'epic' => 18, 'legendary' => 150]])
            ->and($report['spent'])->toBeGreaterThan(100_000)
            // The anchors price the tiers up to the tenth, and the crates of the game are of the first three.
            ->and($report['priced_by'])->toContain('tiers 1 to 10');
    });

    it('lets the player reach the first rare part on a day that the report names, and the epic and legendary ones rarely or never', function () {
        $report = gameWithCrates(0.3)['A']->notes['crates'];

        // The crates of a tier begin again at every tier, and the player is in the tenth for the last days: the first rare part comes on day 8 and an epic
        // one on day 23 (in a middle tier), and no legendary one is reached, because 150 crates of one tier are more than the player opens in it.
        expect($report['first_rare_day'])->toBe(8)
            ->and($report['first_epic_day'])->toBe(23)
            ->and($report['first_legendary_day'])->toBeNull();
    });

    it('is a worse deal than a step of the ladder at the start, and a better one from the second rung on', function () {
        $rules = economyRules();
        $economy = app(GameCrates::class)->economy();
        $payback = new CratePayback;
        $player = $rules->players()['A'];
        $row = fn (int $rung, int $level): array => $payback->compare($rules, $player, $economy, $rung, $level, [0])[0];

        // Days to pay back, for the first crate of the tier at the level where the rung is bought: a crate takes 5.3 days, and the first step 2.4. The first
        // crate lifts the car past the quickest bot, which the table of the gain counts (+10.5% of the income), so from the second rung on it pays back sooner.
        expect($row(0, 1)['crate_days'])->toBeGreaterThan($row(0, 1)['step_days'] * 2)
            ->and($row(1, 3)['crate_days'])->toBeLessThan($row(1, 3)['step_days'])
            ->and($row(4, 12)['crate_days'])->toBeLessThan($row(4, 12)['step_days'])
            ->and($row(5, 15)['crate_days'])->toBeLessThan($row(5, 15)['step_days'] / 10);
    });
});
