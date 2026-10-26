<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * What the extras of the scenario game showed (`ExtrasStudy`): the players played without them and with each of them at each share of the income,
 * the gate of the shift of the days, and the comparisons that are gates of their own (what a crate pays back against a car, what a unit returns).
 * Shares are the keys of the sweeps, as text (`0.2`).
 */
final readonly class ExtrasResult
{
    /**
     * @param  array<string, PlayerRun>  $baseline  the scenario game without extras
     * @param  array<string, array<string, PlayerRun>>  $crates  by share of the income spent on crates, then by player
     * @param  array<string, GateReport>  $crateShift  by share
     * @param  list<array{rung: string, level: int, price: float|null, crates: list<array{crates: int, crate_days: float|null}>, step_days: float|null, crate_pays_back_sooner: bool}>  $crateValue  for the first player, by rung of the ladder
     * @param  array<string, array<string, PlayerRun>>  $units  by share of the income spent on storage units, then by player
     * @param  array<string, GateReport>  $unitShift  by share
     * @param  list<array{kind: string, price: float, unlock_level: int, mean_return: float, chance_double: float, ok: bool}>  $unitReturns  by kind of unit, from the cheapest
     * @param  array<string, PlayerRun>  $sales  the scenario game with a car sold for a share of what was paid (information, not a gate)
     */
    public function __construct(
        public array $baseline,
        public array $crates,
        public array $crateShift,
        public array $crateValue,
        public array $units,
        public array $unitShift,
        public array $unitReturns,
        public array $sales,
        public string $valuedPlayer,
    ) {}

    /** Whether no share of the income spent on crates moves a day of a purchase of the ladder out of the tolerance. */
    public function crateShiftPassed(): bool
    {
        return array_filter($this->crateShift, static fn (GateReport $report): bool => ! $report->passed()) === [];
    }

    /** Whether a crate never pays back sooner than the next car of the ladder does: if it did, the player would spend on crates before cars. */
    public function crateValuePassed(): bool
    {
        return array_filter($this->crateValue, static fn (array $row): bool => $row['crate_pays_back_sooner']) === [];
    }

    public function unitShiftPassed(): bool
    {
        return array_filter($this->unitShift, static fn (GateReport $report): bool => ! $report->passed()) === [];
    }

    /** Whether every kind of storage unit returns less than it costs on average, so that buying and selling cannot make money. */
    public function unitReturnsPassed(): bool
    {
        return array_filter($this->unitReturns, static fn (array $row): bool => ! $row['ok']) === [];
    }

    public function passed(): bool
    {
        return $this->crateShiftPassed() && $this->crateValuePassed() && $this->unitShiftPassed() && $this->unitReturnsPassed();
    }
}
