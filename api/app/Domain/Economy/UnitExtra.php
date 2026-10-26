<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * The storage units in the scenario game: every day the player puts a share of what the car earns into units, in expected values.
 *
 * The budget of the day is the share of the income of the car, plus what was left of the budgets of the days before. The player opens the most
 * expensive kind of unit that the level opens and that the budget covers at least once, as many of it as the budget covers and the cash allows
 * (the cash is what the player had in the morning and what the car earns that day). The price goes out and what the finds are sold for comes
 * back the same day, on average (the mean return of the kind), so what a day costs is the part of the price that does not come back. A part of
 * the budget that is less than a unit is carried to the next day, and one that the cash did not allow is dropped.
 *
 * Next to the cost the extra counts the chances that a find pays for a whole step of the ladder at once: for every unit opened, the chance that
 * its sale is at least the cost of the next step at that moment. That is a chance and not a day saved: the days a lucky player skips are those
 * chances times the days of income that the step stands for, and a player who is not lucky skips none.
 */
final class UnitExtra implements Extra
{
    private float $carry = 0.0;

    /** @var array<string, int> */
    private array $units = [];

    private float $spent = 0.0;

    private float $returned = 0.0;

    /** What the car of the player earned on the days played, before the factor of the extras: the base of the share of the cost. */
    private float $income = 0.0;

    private float $jumps = 0.0;

    private float $jumpDays = 0.0;

    /** @param  float  $spendShare  the share of the income of the car of a day that goes into units (0.1 is a tenth) */
    public function __construct(private readonly float $spendShare, private readonly UnitEconomy $data)
    {
        foreach ($data->kinds() as $kind) {
            $this->units[$kind] = 0;
        }
    }

    public function name(): string
    {
        return 'units';
    }

    public function day(DayState $state): DayEffect
    {
        $this->income += $state->income;

        $budget = $this->carry + $this->spendShare * $state->income;
        $kind = $this->data->kindFor($state->level, $budget);

        if ($kind === null) {
            $this->carry = $budget;

            return DayEffect::none();
        }

        $price = $this->data->price($kind);
        $wanted = (int) floor($budget / $price);
        $count = min($wanted, (int) floor(($state->cash + $state->income) / $price));

        $this->carry = $budget - $wanted * $price;

        if ($count === 0) {
            return DayEffect::none();
        }

        $this->units[$kind] += $count;
        $this->spent += $count * $price;
        $this->returned += $count * $price * $this->data->meanReturn($kind);

        if ($state->rung < $state->rules->rungs() - 1 && $state->income > 0) {
            $step = $state->rules->stepCost($state->rung);
            $chance = $count * $this->data->chanceOfAtLeast($kind, $step);

            $this->jumps += $chance;
            $this->jumpDays += $chance * $step / $state->income;
        }

        return new DayEffect(-$count * $price * (1 - $this->data->meanReturn($kind)));
    }

    public function stepCostDelta(EconomyRules $rules, int $rung): float
    {
        return 0.0;
    }

    /**
     * @return array{share: float, units: array<string, int>, spent: float, returned: float, net_cost: float, net_share_of_income: float, expected_jumps: float, expected_jump_days: float}
     */
    public function report(): array
    {
        $net = $this->spent - $this->returned;

        return [
            'share' => $this->spendShare,
            'units' => $this->units,
            'spent' => $this->spent,
            'returned' => $this->returned,
            'net_cost' => $net,
            'net_share_of_income' => $this->income > 0 ? $net / $this->income : 0.0,
            'expected_jumps' => $this->jumps,
            'expected_jump_days' => $this->jumpDays,
        ];
    }
}
