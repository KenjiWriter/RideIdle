<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * The gate a ruleset has to pass before it is published (Systemy VI, point 19): the days on which each kind of player
 * reaches the cars of the ladder are within a tolerance of the days of the design. The design says 25%. A day is the
 * resolution of the model, so a target of a day or two also allows a day either way, which the ruleset says (`slack_days`).
 */
final class PaceGate
{
    /** @param  array<string, PlayerRun>  $runs by the key of the kind of player */
    public function evaluate(EconomyRules $rules, array $runs): GateReport
    {
        $rows = [];

        foreach ($runs as $key => $run) {
            foreach ($rules->targets((string) $key) as $index => $target) {
                $rung = $index + 1;
                $day = $run->dayOf($rung);
                $allowed = max($rules->gateTolerance() * $target, $rules->gateSlackDays());

                $rows[] = new GateRow(
                    (string) $key,
                    $rules->rungName($rung),
                    $target,
                    $day,
                    $run->purchaseOf($rung)?->binding(),
                    $day !== null && abs($day - $target) <= $allowed,
                );
            }
        }

        return new GateReport($rows, $rules->gateTolerance(), $rules->gateSlackDays());
    }
}
