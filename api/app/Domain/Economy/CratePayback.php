<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * Whether the next crate is a better deal than the next car: how many days of its own gain it takes a crate to pay back what it costs, against the
 * days it takes the next step of the ladder.
 *
 * - A crate costs what `CrateEconomy::cost` says for the tier of the level, and adds to the income of the car by what the gain of the table grows
 *   with it (`gainAfter(n + 1) - gainAfter(n)`, n being the crates of the tier already opened) times what the car of the rung earns in a day.
 * - A step costs what `EconomyRules::stepCost` says, and adds the difference of what the next car and this one earn in a day.
 *
 * A number of days is how long the purchase takes to pay for itself: the smaller, the better the deal. It is not the whole of the matter (a car also
 * opens a level of the ladder and a crate a rarity), but where a crate pays back much sooner than a step, the players will put their money there.
 *
 * The first crates always pay back sooner than the later ones (each adds less), so the gate of the value of the crates is not about the first one: it is
 * about the whole programme, the crates that it takes to reach the guarantee of a legendary part (`programme`), set against the next car.
 */
final class CratePayback
{
    /**
     * The days that the whole programme of crates (this many of them, the guarantee of a legendary part by default) takes to pay back what it costs, by
     * the gain that it ends with: the crates are bought at the price of the tier of the level, and the gain is the one of the table after all of them.
     * Null when no tier is open or the crates add nothing.
     */
    public function programme(EconomyRules $rules, PlayerType $player, CrateEconomy $crates, int $rung, int $level, ?int $count = null): ?float
    {
        $count ??= $crates->legendaryEvery;
        $tier = $crates->tierAt($rules, $level);
        $perDay = $rules->carDailyIncome($player, $rung) * $crates->gainAfter($count);

        return $tier < 1 || $perDay <= 0.0 ? null : $count * $crates->cost($rules, $tier) / $perDay;
    }

    /**
     * @param  list<int>  $counts  the numbers of crates of the tier already opened that the comparison is made at
     * @return list<array{crates: int, price: float|null, crate_days: float|null, step_days: float|null}> the price of a crate (null when no tier is open), the days it
     *                                                                                                    takes to pay back (null when it adds nothing), and the days of the step (null at the last rung)
     */
    public function compare(EconomyRules $rules, PlayerType $player, CrateEconomy $crates, int $rung, int $level, array $counts = [0, 10, 40]): array
    {
        $tier = $crates->tierAt($rules, $level);
        $price = $tier < 1 ? null : $crates->cost($rules, $tier);
        $income = $rules->carDailyIncome($player, $rung);
        $stepDays = null;

        if ($rung < $rules->rungs() - 1) {
            $gained = $rules->carDailyIncome($player, $rung + 1) - $rules->carDailyIncome($player, $rung);
            $stepDays = $gained > 0 ? $rules->stepCost($rung) / $gained : null;
        }

        $rows = [];

        foreach ($counts as $count) {
            $perDay = $income * ($crates->gainAfter($count + 1) - $crates->gainAfter($count));

            $rows[] = [
                'crates' => $count,
                'price' => $price,
                'crate_days' => $price === null || $perDay <= 0.0 ? null : $price / $perDay,
                'step_days' => $stepDays,
            ];
        }

        return $rows;
    }
}
