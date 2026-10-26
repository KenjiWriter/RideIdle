<?php

declare(strict_types=1);

use App\Domain\Economy\DayEffect;
use App\Domain\Economy\DayState;
use App\Domain\Economy\EconomyRules;
use App\Domain\Economy\Extra;
use App\Domain\Economy\PlayerRun;
use App\Domain\Economy\Purchase;
use App\Domain\Economy\Simulator;

/**
 * An extra made of numbers, for the tests of the place that extras have in the simulator: what it does every day, and what it adds to a step.
 */
function anExtra(float $cash = 0.0, float $factor = 1.0, float $stepDelta = 0.0, string $name = 'test'): Extra
{
    return new class($cash, $factor, $stepDelta, $name) implements Extra
    {
        /** @var list<DayState> */
        public array $seen = [];

        public function __construct(private readonly float $cash, private readonly float $factor, private readonly float $stepDelta, private readonly string $name) {}

        public function name(): string
        {
            return $this->name;
        }

        public function day(DayState $state): DayEffect
        {
            $this->seen[] = $state;

            return new DayEffect($this->cash, $this->factor);
        }

        public function stepCostDelta(EconomyRules $rules, int $rung): float
        {
            return $this->stepDelta;
        }

        public function report(): array
        {
            return ['days' => count($this->seen)];
        }
    };
}

/** @return list<int> the days of the purchases of the ladder of the regular player, in the scenario game, with these extras */
function gameDaysWith(array $extras): array
{
    $run = (new Simulator)->simulateGame(economyRules(), economyRules()->players()['A'], $extras);

    return array_map(static fn (Purchase $purchase): int => $purchase->day, $run->purchases);
}

describe('the extras of the scenario game', function () {
    it('change nothing when there are none or when they do nothing', function () {
        expect(gameDaysWith([]))->toBe([3, 8, 9, 10, 13, 16, 22, 36])
            ->and(gameDaysWith([anExtra()]))->toBe([3, 8, 9, 10, 13, 16, 22, 36]);
    });

    it('are told the day, the rung, the level and what the car earns before they are counted', function () {
        $extra = anExtra();
        $run = (new Simulator)->simulateGame(economyRules(), economyRules()->players()['A'], [$extra]);

        $first = $extra->seen[0];

        expect($first->day)->toBe(1)
            ->and($first->rung)->toBe(0)
            ->and($first->level)->toBeGreaterThanOrEqual(1)
            ->and($first->cash)->toBe(economyRules()->startCash())
            ->and($first->income)->toEqualWithDelta(economyRules()->carDailyIncome(economyRules()->players()['A'], 0), 1e-9)
            ->and(count($extra->seen))->toBe($run->daysSimulated);
    });

    it('spend cash, which slows the player down, and bring cash, which speeds the player up', function () {
        $slower = gameDaysWith([anExtra(cash: -400.0)]);
        $faster = gameDaysWith([anExtra(cash: 400.0)]);

        expect(array_sum($slower))->toBeGreaterThan(array_sum(gameDaysWith([])))
            ->and(array_sum($faster))->toBeLessThan(array_sum(gameDaysWith([])));
    });

    it('multiply the income of the car, and the factors of several extras are multiplied', function () {
        $one = gameDaysWith([anExtra(factor: 1.5)]);
        $both = gameDaysWith([anExtra(factor: 1.5), anExtra(factor: 1.5, name: 'other')]);

        expect(array_sum($one))->toBeLessThan(array_sum(gameDaysWith([])))
            ->and(array_sum($both))->toBeLessThan(array_sum($one));
    });

    it('add to the cost of a step of the ladder, and a step can be made dearer or cheaper', function () {
        $dearer = gameDaysWith([anExtra(stepDelta: 2_000.0)]);
        $cheaper = gameDaysWith([anExtra(stepDelta: -1_000.0)]);

        expect($dearer[0])->toBeGreaterThan(3)
            ->and(array_sum($dearer))->toBeGreaterThan(array_sum(gameDaysWith([])))
            ->and(array_sum($cheaper))->toBeLessThan(array_sum(gameDaysWith([])));
    });

    it('say what they did, by their name, in the notes of the run', function () {
        $run = (new Simulator)->simulateGame(economyRules(), economyRules()->players()['B'], [anExtra(name: 'first'), anExtra(name: 'second')]);

        expect(array_keys($run->notes))->toBe(['first', 'second'])
            ->and($run->notes['first'])->toBe(['days' => $run->daysSimulated]);
    });

    it('are made again for every kind of player by the maker that is given, and leave the others as they are', function () {
        $made = [];
        $runs = (new Simulator)->simulateAllGame(economyRules(), static function ($player) use (&$made): array {
            $made[] = $player->key;

            return [anExtra(cash: -100.0, name: 'spender')];
        });

        expect($made)->toBe(['A', 'B', 'C'])
            ->and(array_map(static fn (PlayerRun $run): array => array_keys($run->notes), $runs))->toBe(['A' => ['spender'], 'B' => ['spender'], 'C' => ['spender']])
            ->and((new Simulator)->simulateAllGame(economyRules())['A']->notes)->toBe([]);
    });

    it('are tried at shares that the ruleset names', function () {
        expect(economyRules()->cratesSpendSweep())->toBe([0.1, 0.2, 0.3])
            ->and(economyRules()->unitsSpendSweep())->toBe([0.1, 0.2, 0.3]);
    });
});
