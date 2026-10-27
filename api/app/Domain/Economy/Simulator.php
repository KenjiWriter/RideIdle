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

    /**
     * Plays a kind of player up the ladder with the fleet that the rules of the game let it have (the scenario game), instead of the
     * fleet factor of the design. The main car earns what one car of its rung earns, and every wreck bought into a free place earns what a
     * car of the first rung earns and drives as long as it. Every car gives the player experience, the others by `otherCarsXpShare`.
     *
     * Every day, after the income and the experience, the player buys what pays back soonest, as long as the money is there: the next car of
     * the ladder (when the level asks no more), a wreck (when a place is free), or, when no place is, a garage in a city that the level
     * opens (its payback is that of the three wrecks it makes room for). A purchase earns from the next day on. The player stops
     * simulating at the last rung, but spends what is left of that day on wrecks, as it would.
     *
     * Extras (`Extra`: crates, storage units, the way a car is sold) are played along: each day, after the experience and before anything is bought,
     * they say what they spend or bring and by what factor they change the income of the car, and they add to the cost of a step of the ladder.
     *
     * Nothing here is random and nothing reads a clock, so a ruleset always gives the same days.
     *
     * @param  list<Extra>  $extras  made for this player alone, since they keep their own state
     */
    public function simulateGame(EconomyRules $rules, PlayerType $player, array $extras = []): PlayerRun
    {
        $fleet = $rules->fleetModel();
        $last = $rules->rungs() - 1;
        // The player begins with what the tutorial gave in the first quarter of an hour: experience, cash and a garage of another city.
        $gifts = $rules->tutorialGifts();
        $cash = $rules->startCash() + $gifts->cash;
        $xp = $gifts->xp;
        $rung = 0;
        $wrecks = 0;
        $garages = $gifts->freeGarages;
        $purchases = [];
        $cashReadyDay = null;
        $levelReadyDay = null;
        $maxDays = $rules->gateMaxDays();
        $wreckDaily = $rules->carDailyIncome($player, 0);
        $wreckHours = $player->hoursPerDay($rules->offlineWindowHours(0));
        $day = 0;
        $stepCost = static fn (int $from): float => $rules->stepCost($from) + array_sum(array_map(static fn (Extra $extra): float => $extra->stepCostDelta($rules, $from), $extras));

        while ($day < $maxDays && $rung < $last) {
            $day++;

            $hours = $player->hoursPerDay($rules->offlineWindowHours($rung)) + $fleet->otherCarsXpShare * $wrecks * $wreckHours;
            $xp += $rules->xpPerHour($player) * $hours;
            $level = $rules->levelAt($xp);

            $income = $rules->carDailyIncome($player, $rung);
            $factor = 1.0;
            $brought = 0.0;

            foreach ($extras as $extra) {
                $effect = $extra->day(new DayState($rules, $player, $day, $rung, $level, $income, $cash));
                $factor *= $effect->incomeFactor;
                $brought += $effect->cash;
            }

            $cash += $income * $factor + $wrecks * $wreckDaily + $brought;

            while (true) {
                $options = [];

                if ($rung < $last) {
                    $cost = $stepCost($rung);
                    $cashReadyDay = $cash >= $cost ? ($cashReadyDay ?? $day) : null;
                    $levelIsThere = $level >= $rules->minPlayerLevel($rung + 1);
                    $levelReadyDay ??= $levelIsThere ? $day : null;
                    $gain = $rules->carDailyIncome($player, $rung + 1) - $rules->carDailyIncome($player, $rung);

                    if ($levelIsThere && $gain > 0) {
                        $options[] = ['step', $cost, $cost / $gain];
                    }
                }

                if ($wreckDaily > 0) {
                    if ($fleet->places($garages) > $wrecks) {
                        $options[] = ['wreck', $fleet->wreckPrice, $fleet->wreckPrice / $wreckDaily];
                    } elseif ($garages < $fleet->garagesOpenAt($level)) {
                        // The garage is paid for first, and a wreck for the first place of it is what the money has to cover as well.
                        $options[] = ['garage', $fleet->garagePrice + $fleet->wreckPrice, ($fleet->garagePrice + $fleet->garageCapacity * $fleet->wreckPrice) / ($fleet->garageCapacity * $wreckDaily)];
                    }
                }

                if ($options === []) {
                    break;
                }

                // The sort is stable, so of two that pay back as soon, the car of the ladder comes first.
                usort($options, static fn (array $a, array $b): int => $a[2] <=> $b[2]);
                [$kind, $needed] = $options[0];

                if ($cash < $needed) {
                    break;
                }

                if ($kind === 'step') {
                    $cash -= $stepCost($rung);
                    $rung++;
                    $purchases[] = new Purchase($rung, $day, (int) $cashReadyDay, (int) $levelReadyDay);
                    $cashReadyDay = null;
                    $levelReadyDay = null;
                } elseif ($kind === 'wreck') {
                    $cash -= $fleet->wreckPrice;
                    $wrecks++;
                } else {
                    $cash -= $fleet->garagePrice;
                    $garages++;
                }
            }
        }

        $notes = [];

        foreach ($extras as $extra) {
            $notes[$extra->name()] = $extra->report();
        }

        return new PlayerRun($player, $purchases, $day, $cash, $xp, $wrecks, $garages, $notes);
    }

    /**
     * Every kind of player of the rules, played in the scenario game.
     *
     * @param  (callable(PlayerType): list<Extra>)|null  $extrasFor  makes the extras of a player, new ones for each
     * @return array<string, PlayerRun>
     */
    public function simulateAllGame(EconomyRules $rules, ?callable $extrasFor = null): array
    {
        $runs = [];

        foreach ($rules->players() as $key => $player) {
            $runs[$key] = $this->simulateGame($rules, $player, $extrasFor === null ? [] : $extrasFor($player));
        }

        return $runs;
    }
}
