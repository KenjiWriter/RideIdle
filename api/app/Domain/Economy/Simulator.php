<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * Plays a kind of player, a day at a time, up the ladder of cars (Systemy VI, point 18).
 *
 * Each day the car drives for as many hours as the visits of the player pay for, the profit of those hours less the
 * upkeep goes into the cash, and the experience of them into the level. When the cash covers the next step (the next
 * car, less what the old one is sold for) and the level is the one the dealer asks for, the player buys, on that day,
 * and goes on with the new car the next one. Two limits at once decide when: the money and the level, and the one that
 * takes longer is the one the player waits for.
 *
 * Nothing here is random and nothing reads a clock, so a ruleset always gives the same days.
 */
final class Simulator
{
    public function simulate(EconomyRules $rules, PlayerType $player): PlayerRun
    {
        $last = $rules->rungs() - 1;
        $cash = $rules->startCash();
        $xp = 0.0;
        $rung = 0;
        $purchases = [];
        $cashReadyDay = null;
        $levelReadyDay = null;
        $maxDays = $rules->gateMaxDays();
        $day = 0;

        while ($day < $maxDays && $rung < $last) {
            $day++;

            $hours = $player->hoursPerDay($rules->offlineWindowHours($rung));
            $cash += $rules->dailyIncome($player, $rung);
            $xp += $rules->xpPerHour($player) * $hours;

            // More than one car can be bought in a day, when the money and the level are there for the next one as well.
            while ($rung < $last) {
                $cost = $rules->stepCost($rung);
                $moneyIsThere = $cash >= $cost;
                $levelIsThere = $rules->levelAt($xp) >= $rules->minPlayerLevel($rung + 1);

                $cashReadyDay ??= $moneyIsThere ? $day : null;
                $levelReadyDay ??= $levelIsThere ? $day : null;

                if (! $moneyIsThere || ! $levelIsThere) {
                    break;
                }

                $cash -= $cost;
                $rung++;
                $purchases[] = new Purchase($rung, $day, (int) $cashReadyDay, (int) $levelReadyDay);
                $cashReadyDay = null;
                $levelReadyDay = null;
            }
        }

        return new PlayerRun($player, $purchases, $day, $cash, $xp);
    }

    /**
     * Every kind of player of the rules.
     *
     * @return array<string, PlayerRun>
     */
    public function simulateAll(EconomyRules $rules): array
    {
        $runs = [];

        foreach ($rules->players() as $key => $player) {
            $runs[$key] = $this->simulate($rules, $player);
        }

        return $runs;
    }
}
