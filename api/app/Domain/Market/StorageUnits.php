<?php

declare(strict_types=1);

namespace App\Domain\Market;

use App\Domain\Run\Rng;

/**
 * The storage units of a city (the idea of the storage wars): a locked unit with a random car in it. The kind of unit says the odds of what
 * is in it (scrap, junk, a good car, a gem), the class says how old the car is and what shape it is in, and the value is the one of the
 * formula of the exchange. The draw is made from a seed that the server makes and the driver cannot know, so it cannot be fished for; the same
 * seed always gives the same car.
 *
 * A kind of unit that has not given a good car or better for `pity_every` openings in a row gives one the next time, so that bad luck ends.
 * A gem is never worth more than the cap of its kind (a small unit does not skip the ladder of cars).
 */
final class StorageUnits
{
    /** How many cars are tried for a gem that would be worth more than the cap, before the cheapest of them is taken. */
    private const ATTEMPTS = 20;

    /** One defect is a fifth of the condition, as on the exchange. */
    private const DEFECT_PPM = 200_000;

    /** @param  array<string, mixed>  $storage  `market.storage` of the rules */
    public function __construct(private readonly array $storage, private readonly UsedCarPricing $pricing) {}

    /** @return array<string, array<string, mixed>> the kinds of unit, by key */
    public function units(): array
    {
        return $this->storage['units'];
    }

    public function pityEvery(): int
    {
        return $this->storage['pity_every'];
    }

    /**
     * The odds of a kind of unit, in percent, in the order scrap, junk, good, gem.
     *
     * @return array<string, float>
     */
    public function oddsPercent(string $unit): array
    {
        $odds = $this->storage['units'][$unit]['odds'];
        $total = array_sum($odds);

        return array_map(static fn (int $weight): float => round(100 * $weight / $total, 1), $odds);
    }

    /**
     * Opens a unit: the car in it. `$sinceGood` is how many openings of this kind of unit the driver has made since the last that gave a good
     * car or better, for the guarantee.
     *
     * @param  list<array{slug: string, class: string, price_cents: int}>  $pool  the cars that can be in it
     */
    public function open(string $seed, string $unit, array $pool, int $sinceGood): StorageOutcome
    {
        $kind = $this->storage['units'][$unit];
        $odds = $kind['odds'];

        // The guarantee: this time it is a good car or a gem, in the proportion of their odds.
        if ($sinceGood + 1 >= $this->storage['pity_every']) {
            $odds = ['scrap' => 0, 'junk' => 0, 'good' => $odds['good'], 'gem' => $odds['gem']];
        }

        $class = $this->classOf($seed, $odds);
        $cap = $class === StorageOutcome::GEM ? ($kind['gem_value_cap_cents'] ?? null) : null;
        $best = null;

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $outcome = $this->car($seed, $class, $pool, $attempt);

            if ($cap === null || $outcome->valueCents <= $cap) {
                return $outcome;
            }

            $best = $best === null || $outcome->valueCents < $best->valueCents ? $outcome : $best;
        }

        return $best;
    }

    /** @param  array<string, int>  $odds */
    private function classOf(string $seed, array $odds): string
    {
        $pick = $this->between($seed, 'class', 0, 0, array_sum($odds) - 1);

        foreach ($odds as $class => $weight) {
            if ($pick < $weight) {
                return $class;
            }

            $pick -= $weight;
        }

        return StorageOutcome::SCRAP;
    }

    /** @param  list<array{slug: string, class: string, price_cents: int}>  $pool */
    private function car(string $seed, string $class, array $pool, int $attempt): StorageOutcome
    {
        $ranges = $this->storage['classes'][$class];
        $vehicle = $pool[$this->between($seed, 'vehicle', $attempt, 0, count($pool) - 1)];
        $age = $this->between($seed, 'age', $attempt, $ranges['age_years']['min'], $ranges['age_years']['max']);
        $mileage = intdiv($this->between($seed, 'mileage', $attempt, $ranges['mileage_km']['min'], $ranges['mileage_km']['max']) + 500, 1000) * 1000;
        $condition = $this->between($seed, 'condition', $attempt, $ranges['condition_ppm']['min'], $ranges['condition_ppm']['max']);

        return new StorageOutcome(
            class: $class,
            vehicle: $vehicle['slug'],
            ageYears: $age,
            mileageKm: $mileage,
            conditionPpm: $condition,
            defects: (int) round((1_000_000 - $condition) / self::DEFECT_PPM),
            valueCents: $this->pricing->priceCents($vehicle['price_cents'], $vehicle['class'], $age, $mileage, $condition),
        );
    }

    /** A whole number from `$min` to `$max`, the same for the same seed, domain and index. */
    private function between(string $seed, string $domain, int $index, int $min, int $max): int
    {
        return $min + intdiv(Rng::roll($seed, $domain, $index) * ($max - $min + 1), 1_000_000);
    }
}
