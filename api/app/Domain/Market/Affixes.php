<?php

declare(strict_types=1);

namespace App\Domain\Market;

use App\Domain\Run\Rarity;
use App\Domain\Run\Rng;

/**
 * The affixes of a part (Systemy I): the bonuses that a part above common has, drawn once when the part gets its rarity and kept. Pure: the same seed gives the
 * same affixes on every machine, and the seed is made by the server from a secret, so that nobody can know them before the part is made.
 *
 * A rarity gives a number of affixes (between `min` and `max` of the rules), and a part that becomes the next rarity by merging keeps what it has and gets what it
 * lacks, so a rarity gives more affixes, not bigger ones. An affix is drawn among those that fit the slot of the part, by weight, and a part never has the same one
 * twice; its amount is drawn between the least and the most it gives, in ppm.
 */
final class Affixes
{
    /** @param  array{per_rarity?: array<string, array{min: int, max: int}>, definitions?: array<string, array<string, mixed>>}  $config  `affixes` of the rules */
    public function __construct(private readonly array $config) {}

    /** How many affixes a part of the rarity has: between the least and the most the rules give it, by the seed. A common part has none. */
    public function countFor(string $seed, Rarity $rarity): int
    {
        $range = $this->config['per_rarity'][$rarity->key()] ?? null;

        if ($range === null) {
            return 0;
        }

        $span = max(1, $range['max'] - $range['min'] + 1);

        return $range['min'] + intdiv(Rng::roll($seed, 'affix-count', 0) * $span, 1_000_000);
    }

    /**
     * The affixes of a part of a slot that has the rarity: the ones it has already, and the ones it lacks.
     *
     * @param  array<string, int>  $have  what the part has, by key, with its amount in ppm
     * @return array<string, int>
     */
    public function roll(string $seed, string $slot, Rarity $rarity, array $have = []): array
    {
        $target = $this->countFor($seed, $rarity);
        $affixes = $have;

        for ($draw = 0; count($affixes) < $target; $draw++) {
            $pool = array_filter(
                $this->config['definitions'] ?? [],
                static fn (array $affix, string $key): bool => ! isset($affixes[$key]) && in_array($slot, $affix['slots'], true),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($pool === []) {
                break;
            }

            $pick = intdiv(Rng::roll($seed, 'affix-pick', $draw) * array_sum(array_column($pool, 'weight')), 1_000_000);

            foreach ($pool as $key => $affix) {
                if ($pick < $affix['weight']) {
                    $affixes[$key] = $affix['min_ppm'] + intdiv(Rng::roll($seed, "affix-value:{$key}", 0) * ($affix['max_ppm'] - $affix['min_ppm'] + 1), 1_000_000);

                    break;
                }

                $pick -= $affix['weight'];
            }
        }

        return $affixes;
    }

    /**
     * What a part of a slot and a rarity has on average, for a table that cannot draw every part (the pace of the rarities): for each affix that fits the slot, its
     * mean amount times the chance that the part has it, which is the mean number of affixes of the rarity times the weight of the affix among the ones that fit
     * (never more than 1). It is the same on every machine, and a part that has the affix has it in full: this is the expectation of the effect, not a part.
     *
     * @return array<string, int> the amount in ppm by key, for the affixes that count
     */
    public function expected(string $slot, Rarity $rarity): array
    {
        $range = $this->config['per_rarity'][$rarity->key()] ?? null;
        $pool = array_filter($this->config['definitions'] ?? [], static fn (array $affix): bool => in_array($slot, $affix['slots'], true));

        if ($range === null || $pool === []) {
            return [];
        }

        $count = ($range['min'] + $range['max']) / 2;
        $total = array_sum(array_column($pool, 'weight'));
        $expected = [];

        foreach ($pool as $key => $affix) {
            $amount = (int) round(min(1.0, $count * $affix['weight'] / $total) * ($affix['min_ppm'] + $affix['max_ppm']) / 2);

            if ($amount > 0) {
                $expected[$key] = $amount;
            }
        }

        return $expected;
    }
}
