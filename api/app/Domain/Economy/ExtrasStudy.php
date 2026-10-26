<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * Plays the extras of the scenario game (stages 2 and 3 of the simulator): the crates of parts and the storage units, each at the shares of the
 * income of a day that the ruleset names, and the sale of a car for a share of what was paid. Pure: the numbers of the game come in as
 * `CrateEconomy` and `UnitEconomy`.
 *
 * The gates it prepares are the ones of the extension plan: no share moves the day of a purchase of the ladder by more than the tolerance of the
 * pace gate (against the same scenario without the extra), the whole programme of crates (to the guarantee of a legendary part) does not pay back
 * sooner than the next car of the ladder, and a kind of unit returns less than it costs.
 */
final class ExtrasStudy
{
    /** The numbers of crates already opened at which the payback of the next one is compared with the one of the next car. */
    private const CRATE_COUNTS = [0, 10, 40];

    public function __construct(
        private readonly Simulator $simulator,
        private readonly ShiftGate $gate,
        private readonly CratePayback $payback,
    ) {}

    /** @param  list<string>  $only  the kinds of players to play, all of them when empty */
    public function run(EconomyRules $rules, CrateEconomy $crates, UnitEconomy $units, array $only = []): ExtrasResult
    {
        $baseline = $this->keep($this->simulator->simulateAllGame($rules), $only);

        $crateRuns = [];
        $crateShift = [];

        foreach ($rules->cratesSpendSweep() as $share) {
            $crateRuns[(string) $share] = $this->keep($this->simulator->simulateAllGame($rules, static fn (): array => [new CrateExtra($share, $crates)]), $only);
            $crateShift[(string) $share] = $this->gate->evaluate($rules, $baseline, $crateRuns[(string) $share]);
        }

        $unitRuns = [];
        $unitShift = [];

        foreach ($rules->unitsSpendSweep() as $share) {
            $unitRuns[(string) $share] = $this->keep($this->simulator->simulateAllGame($rules, static fn (): array => [new UnitExtra($share, $units)]), $only);
            $unitShift[(string) $share] = $this->gate->evaluate($rules, $baseline, $unitRuns[(string) $share]);
        }

        $valued = (string) array_key_first($baseline);

        return new ExtrasResult(
            $baseline,
            $crateRuns,
            $crateShift,
            $valued === '' ? [] : $this->crateValue($rules, $crates, $rules->players()[$valued]),
            $unitRuns,
            $unitShift,
            $this->unitReturns($units),
            $this->keep($this->simulator->simulateAllGame($rules, static fn (): array => [new SalesExtra]), $only),
            $valued,
        );
    }

    /**
     * What a crate pays back against the next car, at every rung, for one kind of player: the first crates (information: they are the best a crate does) and
     * the whole programme (the gate).
     *
     * @return list<array{rung: string, level: int, price: float|null, crates: list<array{crates: int, crate_days: float|null}>, programme_crates: int, programme_days: float|null, step_days: float|null, first_crate_pays_back_sooner: bool, crate_pays_back_sooner: bool}>
     */
    private function crateValue(EconomyRules $rules, CrateEconomy $crates, PlayerType $player): array
    {
        $rows = [];

        for ($rung = 0; $rung < $rules->rungs(); $rung++) {
            $level = $rules->minPlayerLevel($rung);
            $compared = $this->payback->compare($rules, $player, $crates, $rung, $level, self::CRATE_COUNTS);
            $first = $compared[0];
            $programme = $this->payback->programme($rules, $player, $crates, $rung, $level);

            $rows[] = [
                'rung' => $rules->rungName($rung),
                'level' => $level,
                'price' => $first['price'],
                'crates' => array_map(static fn (array $row): array => ['crates' => $row['crates'], 'crate_days' => $row['crate_days']], $compared),
                'step_days' => $first['step_days'],
                'programme_crates' => $crates->legendaryEvery,
                'programme_days' => $programme,
                // The first crate is the best a crate does, and it pays back sooner than a car from the rungs where the cars become dear: that is what the gain does
                // as it falls, and the report says it, but it is not what the gate asks.
                'first_crate_pays_back_sooner' => $first['crate_days'] !== null && $first['step_days'] !== null && $first['crate_days'] < $first['step_days'],
                'crate_pays_back_sooner' => $programme !== null && $first['step_days'] !== null && $programme < $first['step_days'],
            ];
        }

        return $rows;
    }

    /** @return list<array{kind: string, price: float, unlock_level: int, mean_return: float, chance_double: float, ok: bool}> */
    private function unitReturns(UnitEconomy $units): array
    {
        return array_map(static fn (string $kind): array => [
            'kind' => $kind,
            'price' => $units->price($kind),
            'unlock_level' => $units->unlockLevel($kind),
            'mean_return' => $units->meanReturn($kind),
            'chance_double' => $units->chanceOfAtLeast($kind, 2 * $units->price($kind)),
            'ok' => $units->meanReturn($kind) < 1.0,
        ], $units->kinds());
    }

    /**
     * @param  array<string, PlayerRun>  $runs
     * @param  list<string>  $only
     * @return array<string, PlayerRun>
     */
    private function keep(array $runs, array $only): array
    {
        return $only === [] ? $runs : array_intersect_key($runs, array_flip($only));
    }
}
