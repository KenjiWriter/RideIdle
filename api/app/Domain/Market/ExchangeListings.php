<?php

declare(strict_types=1);

namespace App\Domain\Market;

use App\Domain\Run\Rng;
use App\Domain\Settlement\Ppm;

/**
 * The cars on the used-car exchange of a city (Systemy III, point 11). A set of listings lasts one refresh (about half an hour), and it is
 * not stored: it is made again, the same, from the seed of the driver, the city and the number of the refresh, so nothing has to be written
 * until a driver inspects or buys a car. Every driver has an exchange of their own, so there is nobody to race for a car.
 *
 * A car has a mileage, an age that follows from it, and a number of defects that depends on the mileage; the defects make its condition,
 * the condition makes its price, and the listing says only a word about it that is a little off the truth. An inspection tells the truth.
 */
final class ExchangeListings
{
    /** @param  array<string, mixed>  $exchange  `market.exchange` of the rules */
    public function __construct(private readonly array $exchange, private readonly UsedCarPricing $pricing) {}

    /** The number of the refresh that the moment falls in. */
    public function window(int $nowMs): int
    {
        return intdiv($nowMs, $this->exchange['refresh_ms']);
    }

    /** When the listings that are on now go. */
    public function nextRefreshMs(int $nowMs): int
    {
        return ($this->window($nowMs) + 1) * $this->exchange['refresh_ms'];
    }

    /**
     * @param  list<array{slug: string, class: string, price_cents: int}>  $vehicles  the cars that may be listed, in a fixed order
     * @return list<UsedListing>
     */
    public function listings(string $seed, string $region, int $window, array $vehicles): array
    {
        $listings = [];

        for ($index = 0; $vehicles !== [] && $index < $this->exchange['listings']; $index++) {
            $listings[] = $this->listing($seed, $region, $window, $index, $vehicles);
        }

        return $listings;
    }

    /**
     * The listing a key names, or null when it is gone: the exchange was refreshed since, the key is not one of the exchange, or the car on
     * that place is not the one the key was made for (the driver can list other cars than a moment ago).
     *
     * @param  list<array{slug: string, class: string, price_cents: int}>  $vehicles
     */
    public function find(string $key, string $seed, int $nowMs, array $vehicles): ?UsedListing
    {
        if ($vehicles === [] || preg_match('/^([a-z0-9-]+)\.(\d+)\.(\d+)\.[0-9a-f]{8}$/', $key, $match) !== 1) {
            return null;
        }

        [, $region, $window, $index] = $match;

        if ((int) $window !== $this->window($nowMs) || (int) $index >= $this->exchange['listings']) {
            return null;
        }

        $listing = $this->listing($seed, $region, (int) $window, (int) $index, $vehicles);

        return $listing->key === $key ? $listing : null;
    }

    /** What an inspection of a car costs: a share of its price, in whole dollars. */
    public function inspectionCostCents(int $priceCents): int
    {
        return max(100, intdiv(Ppm::mul($priceCents, $this->exchange['inspection_ppm']) + 50, 100) * 100);
    }

    /** @param  list<array{slug: string, class: string, price_cents: int}>  $vehicles */
    private function listing(string $seed, string $region, int $window, int $index, array $vehicles): UsedListing
    {
        $domain = "exchange:{$region}:{$window}";
        $vehicle = $vehicles[$this->between($seed, "{$domain}:vehicle", $index, 0, count($vehicles) - 1)];

        $mileage = $this->between($seed, "{$domain}:mileage", $index, $this->exchange['mileage_km']['min'], $this->exchange['mileage_km']['max']);
        $mileage = intdiv($mileage + 500, 1000) * 1000;

        $perYear = $this->between($seed, "{$domain}:per-year", $index, $this->exchange['km_per_year']['min'], $this->exchange['km_per_year']['max']);
        $age = max($this->exchange['age_years']['min'], min($this->exchange['age_years']['max'], intdiv($mileage + intdiv($perYear, 2), $perYear)));

        $defects = $this->defects($seed, $domain, $index, $mileage);
        $condition = $this->condition($seed, $domain, $index, $defects);
        $price = $this->pricing->priceCents($vehicle['price_cents'], $vehicle['class'], $age, $mileage, $condition);

        $fingerprint = substr(hash('sha256', "{$seed}|{$region}|{$window}|{$index}|{$vehicle['slug']}|{$age}|{$mileage}|{$condition}|{$price}"), 0, 8);

        return new UsedListing(
            key: "{$region}.{$window}.{$index}.{$fingerprint}",
            region: $region,
            window: $window,
            index: $index,
            vehicle: $vehicle['slug'],
            ageYears: $age,
            mileageKm: $mileage,
            conditionPpm: $condition,
            defects: $defects,
            word: $this->word($seed, $domain, $index, $condition),
            priceCents: $price,
        );
    }

    /** How many parts of the car are worn out: more of them the more it has driven. */
    private function defects(string $seed, string $domain, int $index, int $mileage): int
    {
        $weights = null;

        foreach ($this->exchange['defects'] as $band) {
            $weights = $band['weights'];

            if ($mileage <= $band['up_to_km']) {
                break;
            }
        }

        $pick = $this->between($seed, "{$domain}:defects", $index, 0, array_sum($weights) - 1);

        foreach ($weights as $count => $weight) {
            if ($pick < $weight) {
                return $count;
            }

            $pick -= $weight;
        }

        return count($weights) - 1;
    }

    /** A car without defects is in a perfect condition, and every defect takes a share of it. */
    private function condition(string $seed, string $domain, int $index, int $defects): int
    {
        $lost = 0;

        for ($defect = 0; $defect < $defects; $defect++) {
            $lost += $this->between($seed, "{$domain}:defect-{$defect}", $index, $this->exchange['defect_ppm']['min'], $this->exchange['defect_ppm']['max']);
        }

        return $defects === 0 ? Ppm::ONE : max(50_000, Ppm::ONE - $lost);
    }

    /** What the listing says of the car: the condition and an error of its own. */
    private function word(string $seed, string $domain, int $index, int $condition): string
    {
        $noise = $this->exchange['description']['noise_ppm'];
        $seen = $condition + $this->between($seed, "{$domain}:word", $index, -$noise, $noise);

        return $seen >= $this->exchange['description']['good_from_ppm'] ? UsedListing::GOOD : ($seen >= $this->exchange['description']['fair_from_ppm'] ? UsedListing::FAIR : UsedListing::POOR);
    }

    /** A whole number from `$min` to `$max`, the same for the same seed, domain and index. */
    private function between(string $seed, string $domain, int $index, int $min, int $max): int
    {
        return $min + intdiv(Rng::roll($seed, $domain, $index) * ($max - $min + 1), 1_000_000);
    }
}
