<?php

declare(strict_types=1);

use App\Domain\Economy\EconomyRules;
use App\Domain\Economy\PaceGate;
use App\Domain\Economy\PlayerRun;
use App\Domain\Economy\Purchase;
use App\Domain\Economy\Simulator;

/** @return array<string, list<int>> the day of each purchase, by the kind of player */
function daysOf(EconomyRules $rules): array
{
    return array_map(
        static fn ($run): array => array_map(static fn (Purchase $purchase): int => $purchase->day, $run->purchases),
        (new Simulator)->simulateAll($rules),
    );
}

it('gives the days of the design for the three kinds of players', function () {
    expect(daysOf(economyRules()))->toBe([
        'A' => [4, 11, 19, 27, 34, 42, 55, 76],
        'B' => [13, 38, 70, 99, 125, 147, 175, 212],
        'C' => [1, 3, 6, 9, 12, 17, 25, 37],
    ]);
});

it('gives the same days every time it is played', function () {
    expect(daysOf(economyRules()))->toBe(daysOf(economyRules()));
});

it('stops at the last rung, and says how many days it took', function () {
    $run = (new Simulator)->simulate(economyRules(), economyRules()->players()['A']);

    expect($run->daysSimulated)->toBe(76)
        ->and($run->dayOf(8))->toBe(76)
        ->and($run->dayOf(9))->toBeNull();
});

it('finds that the level, and not the money, is what every player waits for', function () {
    $waited = [];

    foreach ((new Simulator)->simulateAll(economyRules()) as $key => $run) {
        foreach ($run->purchases as $purchase) {
            $waited[$purchase->binding()][] = "{$key}{$purchase->rung}";
        }
    }

    // Only the very first car of the intensive player has both on the first day.
    expect(array_keys($waited))->toBe(['level', 'both'])
        ->and($waited['both'])->toBe(['C1']);
});

it('lets the money decide when there is little of it', function () {
    // A twentieth of the profit: the cars take far longer to pay for, and the money is what is waited for.
    $rules = economyRules(['knobs', 'reward_mult'], 0.05);
    $run = (new Simulator)->simulate($rules, $rules->players()['A']);

    expect($run->dayOf(1))->toBeGreaterThan(4)
        ->and($run->purchaseOf(1)->binding())->toBe(Purchase::BINDING_CASH);

    $cash = array_filter($run->purchases, static fn (Purchase $purchase): bool => $purchase->binding() === Purchase::BINDING_CASH);

    expect($cash)->not->toBeEmpty();
});

it('never buys a car that is out of reach in the days it is given', function () {
    $rules = economyRules(['gate', 'max_days'], 30);
    $run = (new Simulator)->simulate($rules, $rules->players()['B']);

    // The occasional player gets two cars in thirty days, and the rest not at all.
    expect($run->dayOf(1))->toBe(13)
        ->and($run->dayOf(2))->toBeNull()
        ->and($run->daysSimulated)->toBe(30);
});

it('buys two cars in a day when the money and the level are there for both', function () {
    // Enough cash to buy everything the level allows: the intensive player buys L1 and L2 on the days its level reaches them.
    $rules = economyRules(['model', 'start_cash'], 1_000_000);
    $days = daysOf($rules)['C'];

    expect($days[0])->toBeLessThanOrEqual(1)
        ->and(count($days))->toBe(8)
        // With no money to wait for, the days are those of the levels alone, and never later than with 1 200.
        ->and($days[7])->toBeLessThanOrEqual(37);
});

describe('the pace gate', function () {
    it('passes the ruleset of the repository', function () {
        $rules = economyRules();
        $report = (new PaceGate)->evaluate($rules, (new Simulator)->simulateAll($rules));

        expect($report->passed())->toBeTrue()
            ->and($report->failures())->toBe([])
            ->and($report->rows)->toHaveCount(24);
    });

    it('fails a ruleset that makes the cars come far too fast, and names the lines', function () {
        // Three times the experience: levels come three times as fast, and the cars with them.
        $rules = economyRules(['model', 'xp', 'per_hour'], 270);
        $report = (new PaceGate)->evaluate($rules, (new Simulator)->simulateAll($rules));

        expect($report->passed())->toBeFalse();

        $failed = array_map(static fn ($row): string => "{$row->player}:{$row->rung}", $report->failures());
        expect($failed)->toContain('A:L8')->and($failed)->toContain('B:L8');

        $row = collect($report->rows)->first(static fn ($row): bool => $row->player === 'A' && $row->rung === 'L8');
        expect($row->deviation())->toBeLessThan(-0.25);
    });

    it('fails a ruleset that makes the cars come too slowly', function () {
        $rules = economyRules(['model', 'xp', 'per_hour'], 40);
        $report = (new PaceGate)->evaluate($rules, (new Simulator)->simulateAll($rules));

        $row = collect($report->rows)->first(static fn ($row): bool => $row->player === 'A' && $row->rung === 'L1');

        expect($report->passed())->toBeFalse()
            ->and($row->deviation())->toBeGreaterThan(0.25);
    });

    it('fails a car that was never bought', function () {
        $rules = economyRules(['gate', 'max_days'], 30);
        $report = (new PaceGate)->evaluate($rules, (new Simulator)->simulateAll($rules));

        $row = collect($report->rows)->first(static fn ($row): bool => $row->player === 'A' && $row->rung === 'L8');

        expect($row->day)->toBeNull()
            ->and($row->deviation())->toBeNull()
            ->and($row->passed)->toBeFalse();
    });

    it('allows a day either way on a target of a day or two, and a quarter on the others', function () {
        // Slack of a day: a target of 1 allows day 2, which 25% of 1 would not.
        $slack = economyRules(['gate', 'slack_days'], 1);
        // No slack: the same ruleset must keep to the quarter alone.
        $strict = economyRules(['gate', 'slack_days'], 0);

        $late = static function (EconomyRules $rules): bool {
            $runs = (new Simulator)->simulateAll($rules);
            // Pretend the intensive player got the first car a day late.
            $late = new PlayerRun(
                $runs['C']->player,
                [new Purchase(1, 2, 2, 2), ...array_slice($runs['C']->purchases, 1)],
                $runs['C']->daysSimulated,
                $runs['C']->cash,
                $runs['C']->xp,
            );

            return collect((new PaceGate)->evaluate($rules, ['C' => $late])->rows)->first(static fn ($row): bool => $row->rung === 'L1')->passed;
        };

        expect($late($slack))->toBeTrue()
            ->and($late($strict))->toBeFalse();
    });
});
