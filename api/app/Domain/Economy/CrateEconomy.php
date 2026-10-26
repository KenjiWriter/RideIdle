<?php

declare(strict_types=1);

namespace App\Domain\Economy;

use InvalidArgumentException;

/**
 * What the crates of parts are in the economy of the simulator: when a player level opens a tier of them, what a crate costs, what the parts of
 * the crates add to the income of the car, and how many crates it takes to get a rare, an epic and a legendary part. Pure: the numbers come from
 * the rules of the game (`shop.crates`) and from the table of the pace of the rarities (`content/tables/rarity-pace.json`) through the collector
 * `App\Content\GameCrates`.
 *
 * The price of a crate is a share of the price of the part that the anchors give for its tier and slot (`EconomyRules::partPrice`), so it is
 * known for the tiers 1 to 10 of the anchors, while the pack of the game has parts of the tiers 1 to 3 only: above them the price is what the
 * anchors say a part of that tier would cost, and the table of the gain is the one of the tier 2 parts of the pack.
 */
final readonly class CrateEconomy
{
    /** The rarities from the most common, as the rules call them. */
    public const RARITIES = ['common', 'uncommon', 'rare', 'epic', 'legendary'];

    /**
     * @param  array<string, int>  $slotMix  slot => how many of the crates of a round are for it (tires 2, suspension 1, brakes 1)
     * @param  array<string, int>  $odds  the weights of the rarities of the group of the crates, by the name of the rarity
     * @param  list<float>  $gain  the gain of laps an hour (0.2 is a fifth) after 0, 1, 2, ... crates of one tier: it only grows, and past the last number it stays
     */
    public function __construct(
        public int $firstTierLevel,
        public int $levelsPerTier,
        public int $priceFactorPpm,
        public array $slotMix,
        public array $odds,
        public int $floorEvery,
        public string $floorRarity,
        public int $legendaryEvery,
        public array $gain,
    ) {
        if ($firstTierLevel < 1 || $levelsPerTier < 1 || $priceFactorPpm < 1 || $floorEvery < 1 || $legendaryEvery < 1) {
            throw new InvalidArgumentException('The levels, the price and the guarantees of the crates must be positive.');
        }

        if ($slotMix === [] || min($slotMix) < 1) {
            throw new InvalidArgumentException('The crates need at least one slot, and every slot at least one crate of a round.');
        }

        if ($gain === [] || (float) $gain[0] !== 0.0) {
            throw new InvalidArgumentException('The gain after no crates is none, and the table starts there.');
        }

        if (array_keys($odds) !== self::RARITIES || ! in_array($floorRarity, self::RARITIES, true) || array_sum($this->aboveFloor()) < 1) {
            throw new InvalidArgumentException('The odds name the five rarities, and the guarantee needs something to give.');
        }
    }

    /**
     * The tier of the crates that a player level opens, 0 when none is open yet: tier t opens at the level max(first, levels per tier * (t - 1)), as the
     * game has it, and a tier that the anchors have no price for is left out, the highest one they know being the last.
     */
    public function tierAt(EconomyRules $rules, int $level): int
    {
        if ($level < $this->firstTierLevel) {
            return 0;
        }

        $tier = 1 + intdiv($level, $this->levelsPerTier);

        while ($tier > 1 && ! $this->priced($rules, $tier)) {
            $tier--;
        }

        return $tier;
    }

    /**
     * What a crate of a tier costs, in dollars: the mean of the prices of the parts of its slots, by the mix, times the price factor.
     *
     * @throws InvalidArgumentException when the anchors have no price for a part of the tier
     */
    public function cost(EconomyRules $rules, int $tier): float
    {
        $total = 0.0;

        foreach ($this->slotMix as $slot => $count) {
            $price = $rules->partPrice($tier, $slot) ?? throw new InvalidArgumentException("The anchors have no price for a part of tier {$tier} in the slot {$slot}.");
            $total += $count * $price;
        }

        return $total / array_sum($this->slotMix) * $this->priceFactorPpm / 1_000_000;
    }

    /** What the parts of the crates add to the laps in an hour after this many crates of a tier: a share (0.2 is a fifth). */
    public function gainAfter(int $crates): float
    {
        return $this->gain[min(max(0, $crates), count($this->gain) - 1)];
    }

    /**
     * How many crates it takes, for the half of the players, to get the first part of a rarity or a better one: the smallest number of crates by which
     * the chance of having had one is at least a half. Exact, not drawn: the odds and the two guarantees (a part of the rarity of the floor or a better
     * one every `floorEvery` crates, a legendary one every `legendaryEvery`) are a chain on the counts since each, and the chain is played crate by crate
     * with the probability of every state, so the answer is always the same.
     *
     * @return array{rare: int, epic: int, legendary: int}
     */
    public function mediansToFirst(): array
    {
        return ['rare' => $this->medianTo(2), 'epic' => $this->medianTo(3), 'legendary' => $this->medianTo(4)];
    }

    /**
     * @param  int  $target  the place of the rarity in `RARITIES`: the first part of it or a better one is what the count waits for
     */
    private function medianTo(int $target): int
    {
        $floor = (int) array_search($this->floorRarity, self::RARITIES, true);
        $weights = array_values($this->odds);
        // The mass of the players that have not got one yet, by the crates since the last part of the floor and since the last legendary one.
        $states = ['0,0' => 1.0];
        $reached = 0.0;
        $limit = 2 * ($this->legendaryEvery + $this->floorEvery);

        for ($crate = 1; $crate <= $limit; $crate++) {
            $next = [];

            foreach ($states as $key => $mass) {
                [$sinceFloor, $sinceLegendary] = array_map('intval', explode(',', $key));
                $chances = $this->chances($weights, $floor, $sinceFloor, $sinceLegendary);

                foreach ($chances as $rarity => $chance) {
                    if ($chance <= 0.0) {
                        continue;
                    }

                    if ($rarity >= $target) {
                        $reached += $mass * $chance;

                        continue;
                    }

                    $after = ($rarity >= $floor ? 0 : $sinceFloor + 1).','.($rarity === 4 ? 0 : $sinceLegendary + 1);
                    $next[$after] = ($next[$after] ?? 0.0) + $mass * $chance;
                }
            }

            if ($reached >= 0.5 - 1e-12) {
                return $crate;
            }

            $states = $next;
        }

        throw new InvalidArgumentException('The crates never give a part of that rarity.');
    }

    /**
     * The chance of each rarity for the next crate, given the crates since the last part of the floor and the last legendary one.
     *
     * @param  list<int>  $weights
     * @return list<float>
     */
    private function chances(array $weights, int $floor, int $sinceFloor, int $sinceLegendary): array
    {
        if ($sinceLegendary + 1 >= $this->legendaryEvery) {
            return [0.0, 0.0, 0.0, 0.0, 1.0];
        }

        if ($sinceFloor + 1 >= $this->floorEvery) {
            foreach ($weights as $rarity => $weight) {
                if ($rarity < $floor) {
                    $weights[$rarity] = 0;
                }
            }
        }

        $total = array_sum($weights);

        return array_map(static fn (int $weight): float => $weight / $total, $weights);
    }

    /** @return list<int> the weights of the rarities of the floor and above */
    private function aboveFloor(): array
    {
        $floor = (int) array_search($this->floorRarity, self::RARITIES, true);

        return array_slice(array_values($this->odds), $floor);
    }

    private function priced(EconomyRules $rules, int $tier): bool
    {
        foreach (array_keys($this->slotMix) as $slot) {
            if ($rules->partPrice($tier, $slot) === null) {
                return false;
            }
        }

        return true;
    }
}
