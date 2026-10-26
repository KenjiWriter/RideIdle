<?php

declare(strict_types=1);

namespace App\Domain\Market;

use App\Domain\Run\Rarity;
use App\Domain\Run\Rng;

/**
 * The crates of the shop (Systemy III, point 11): the odds of the rarities, the guarantees, and the draw. Pure: the same seed gives the same
 * part on every machine, and the seed is made by the server from a secret, so that nobody can know the part before they open the crate.
 *
 * Two groups of crates have their own odds and guarantees: the standard ones (a crate of a slot, a mixed one) and the premium ones. A guarantee
 * is counted over the openings of a group: after `every - 1` crates without a part of the rarity of the guarantee, or better, the next one
 * gives one, and after `legendary_every - 1` crates without a legendary part the next one is legendary.
 */
final class Crates
{
    /** @param  array<string, mixed>  $crates  `shop.crates` of the rules */
    public function __construct(private readonly array $crates) {}

    /** @return array<string, array{group: string, price_ppm: int, min_player_level: int, choose_slot: bool}> the kinds of crate, by key */
    public function kinds(): array
    {
        return $this->crates['kinds'];
    }

    /** The player level that opens the crates of a tier: from the dealer's rule, every tier opens `levels_per_tier` levels after the one below. */
    public function playerLevelOfTier(int $tier): int
    {
        return max($this->crates['first_tier_level'], $this->crates['levels_per_tier'] * ($tier - 1));
    }

    public function floorEvery(string $group): int
    {
        return $this->crates['guarantee'][$group]['every'];
    }

    public function floorRarity(string $group): Rarity
    {
        return Rarity::fromKey($this->crates['guarantee'][$group]['min_rarity']);
    }

    public function legendaryEvery(string $group): int
    {
        return $this->crates['guarantee'][$group]['legendary_every'];
    }

    /**
     * The odds of a group in percent, with one decimal, by rarity: what the shop shows before the purchase.
     *
     * @return array<string, float>
     */
    public function oddsPercent(string $group): array
    {
        $weights = $this->crates['odds'][$group];
        $total = array_sum($weights);

        return array_map(static fn (int $weight): float => round(100 * $weight / $total, 1), $weights);
    }

    /**
     * The rarity of the part that the crate gives.
     *
     * @param  int  $sinceFloor  how many crates of the group were opened since the last part of the rarity of the guarantee or better
     * @param  int  $sinceLegendary  how many were opened since the last legendary part
     */
    public function draw(string $seed, string $group, int $sinceFloor, int $sinceLegendary): Rarity
    {
        if ($sinceLegendary + 1 >= $this->legendaryEvery($group)) {
            return Rarity::Legendary;
        }

        $weights = $this->crates['odds'][$group];

        if ($sinceFloor + 1 >= $this->floorEvery($group)) {
            $floor = $this->floorRarity($group);

            foreach (Rarity::cases() as $rarity) {
                if ($rarity->value < $floor->value) {
                    $weights[$rarity->key()] = 0;
                }
            }
        }

        $pick = intdiv(Rng::roll($seed, 'rarity', 0) * array_sum($weights), 1_000_000);

        foreach (Rarity::cases() as $rarity) {
            if ($pick < $weights[$rarity->key()]) {
                return $rarity;
            }

            $pick -= $weights[$rarity->key()];
        }

        return Rarity::Common;
    }

    /** A place from 0 to `$count - 1`, the same for the same seed and domain: which slot, and which model of the slot. */
    public function pick(string $seed, string $domain, int $count): int
    {
        return intdiv(Rng::roll($seed, $domain, 0) * $count, 1_000_000);
    }
}
