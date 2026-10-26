<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * The crates of parts, played in the scenario game of the simulator (stage 2): every day the player spends a share of what the car earns on
 * the crates of the tier that the level opens, mounts the best part of every slot, and the car earns more by what those parts add.
 *
 * - The budget of a day is the share of the income of the day, plus what was left of the days before; the player opens as many whole crates
 *   as it covers, and never more than the cash that the player has (what was in hand when the day began and what the car earns that day).
 * - A crate costs what the anchors say for the parts of the tier (`CrateEconomy::cost`), so the cost grows with the level.
 * - The income of the car is multiplied by 1 plus the gain after the crates opened of the current tier (`CrateEconomy::gainAfter`): the best part
 *   of each slot, drawn with the odds and the guarantees of the game. When the level opens a higher tier the count begins again, because the parts
 *   of the new tier are common and the ones of the lower tier, rare as they are, do not carry over.
 *
 * Every number here is an expected value (the table of the gain is a mean over many drawn players), so nothing is random.
 */
final class CrateExtra implements Extra
{
    private int $tier = 0;

    /** The crates opened of the current tier. */
    private int $inTier = 0;

    private int $opened = 0;

    private float $spent = 0.0;

    /** The budget that was not spent yet, in dollars. */
    private float $carry = 0.0;

    private float $gain = 0.0;

    /** @var array{rare: ?int, epic: ?int, legendary: ?int} the first day on which the crates of a tier were as many as the half of the players need */
    private array $firstDay = ['rare' => null, 'epic' => null, 'legendary' => null];

    /** @var array{rare: int, epic: int, legendary: int} */
    private readonly array $medians;

    /** @param  float  $spendShare  the share of the income of a day that goes into the crates, 0.2 being a fifth */
    public function __construct(private readonly float $spendShare, private readonly CrateEconomy $data)
    {
        $this->medians = $data->mediansToFirst();
    }

    public function name(): string
    {
        return 'crates';
    }

    public function day(DayState $state): DayEffect
    {
        $tier = $this->data->tierAt($state->rules, $state->level);

        if ($tier < 1 || $this->spendShare <= 0.0) {
            return DayEffect::none();
        }

        if ($tier !== $this->tier) {
            $this->tier = $tier;
            $this->inTier = 0;
            $this->gain = $this->data->gainAfter(0);
        }

        $cost = $this->data->cost($state->rules, $tier);
        $this->carry += $this->spendShare * $state->income;
        $count = min((int) floor($this->carry / $cost), (int) floor(($state->cash + $state->income) / $cost));

        if ($count > 0) {
            $this->carry -= $count * $cost;
            $this->spent += $count * $cost;
            $this->opened += $count;
            $this->inTier += $count;
            $this->gain = $this->data->gainAfter($this->inTier);

            foreach ($this->medians as $rarity => $crates) {
                if ($this->firstDay[$rarity] === null && $this->inTier >= $crates) {
                    $this->firstDay[$rarity] = $state->day;
                }
            }
        }

        return new DayEffect(-$count * $cost, 1.0 + $this->gain);
    }

    public function stepCostDelta(EconomyRules $rules, int $rung): float
    {
        return 0.0;
    }

    public function report(): array
    {
        return [
            'share' => $this->spendShare,
            'crates' => $this->opened,
            'spent' => round($this->spent, 2),
            'uplift' => round($this->gain, 4),
            'tier' => $this->tier,
            'crates_in_tier' => $this->inTier,
            'medians' => $this->medians,
            'first_rare_day' => $this->firstDay['rare'],
            'first_epic_day' => $this->firstDay['epic'],
            'first_legendary_day' => $this->firstDay['legendary'],
            'priced_by' => 'the anchors, tiers 1 to 10; the pack of the game has crates of tiers 1 to 3 only',
        ];
    }
}
