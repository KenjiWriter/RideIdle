<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * The ladder of the game against the ladder of the anchors (Systemy VI, point 19, layer 1). The anchors say what an hour of driving pays at each
 * rung of the ladder of cars (`600 * 1.15 ^ rung`), and the pace of the game is only right when the game pays about that to a driver whose
 * level is the one of the rung. What the game pays comes from the pack, as the income of the best car and track a level opens, so this class
 * knows nothing of the pack: the collector (`App\Content\GameIncome`) hands the incomes over by level.
 *
 * The rung of a level is the highest rung whose level is not above it, as the dealer of the ladder asks.
 */
final class GameLadder
{
    /**
     * The levels that the ladder is asked about: the level that opens each rung.
     *
     * @return list<int>
     */
    public function levels(EconomyRules $economy): array
    {
        return array_map(static fn (int $rung): int => $economy->minPlayerLevel($rung), range(0, $economy->rungs() - 1));
    }

    /**
     * The income of the game at each level against the anchor of the rung of that level, from the lowest level to the highest.
     *
     * @param  array<int, array{net: float, car: string, track: string}>  $incomeByLevel  the best net profit per hour of the game by level
     * @return list<LadderRow>
     */
    public function check(EconomyRules $economy, array $incomeByLevel): array
    {
        ksort($incomeByLevel);

        $rows = [];

        foreach ($incomeByLevel as $level => $income) {
            $rung = $this->rungOf($economy, $level);
            $anchor = $economy->netPerHour($rung);
            $ok = abs($income['net'] - $anchor) / $anchor <= $economy->consistencyTolerance();

            $rows[] = new LadderRow($level, $economy->rungName($rung), $anchor, $income['net'], $income['car'], $income['track'], $ok);
        }

        return $rows;
    }

    /**
     * The rungs whose anchor pays more than the best the game pays at any level: nothing in the game reaches them. `$tolerance` lets a rung count as
     * reached when the game gets within that share of it (0 asks for all of it).
     *
     * @param  array<int, array{net: float, car: string, track: string}>  $incomeByLevel
     * @return array<int, string> the names of the rungs, by the number of the rung
     */
    public function unreachableRungs(EconomyRules $economy, array $incomeByLevel, float $tolerance = 0.0): array
    {
        $best = $incomeByLevel === [] ? 0.0 : max(array_column($incomeByLevel, 'net'));
        $unreachable = [];

        foreach (range(0, $economy->rungs() - 1) as $rung) {
            if ($economy->netPerHour($rung) * (1 - $tolerance) > $best) {
                $unreachable[$rung] = $economy->rungName($rung);
            }
        }

        return $unreachable;
    }

    /** The highest rung that a driver of this level can have a car of: the first one for a level below every rung. */
    private function rungOf(EconomyRules $economy, int $level): int
    {
        $rung = 0;

        foreach (range(0, $economy->rungs() - 1) as $candidate) {
            if ($economy->minPlayerLevel($candidate) <= $level) {
                $rung = $candidate;
            }
        }

        return $rung;
    }
}
