<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Economy\UnitEconomy;
use App\Domain\Market\StorageOutcome;
use App\Domain\Market\StorageUnits;
use App\Domain\Market\UsedCarPricing;
use InvalidArgumentException;

/**
 * What the storage units of the game pay back when the find is sold at once, from the real domain: the cars of the pack, the odds and the
 * guarantee of `market.storage`, the formula of the price of a used car and the rule of the sale (a share of the value, never less than the
 * scrap of the mass). It is the table `content/tables/unit-returns.json`, which the economy simulator reads, and the same code writes it, so that a
 * test can say whether it is up to date.
 *
 * Every kind of unit is opened `TRIALS` times by one driver, the guarantee counted as in the game, and again with no guarantee. The seeds are
 * strings (`unit-returns|{kind}|{n}`), so the table is the same on every machine.
 */
final class GameUnits
{
    /** How many units of each kind are opened for the table. */
    public const TRIALS = 40_000;

    public const SEED_PREFIX = 'unit-returns';

    /** The percents of the sales that are at most the value on the table: every one from 0 to 99, and the tail, which is where the jackpots are. */
    public const PERCENTILES = [
        0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39,
        40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75, 76, 77, 78, 79,
        80, 81, 82, 83, 84, 85, 86, 87, 88, 89, 90, 91, 92, 93, 94, 95, 96, 97, 98, 99, 99.5, 99.9, 100,
    ];

    public function __construct(private readonly Catalog $catalog, private readonly Rules $rules) {}

    public function path(): string
    {
        return rtrim((string) config('ride.content_path'), '/\\').'/tables/unit-returns.json';
    }

    /**
     * The table, computed.
     *
     * @return array<string, mixed>
     */
    public function computed(int $trials = self::TRIALS): array
    {
        $storage = $this->rules->marketStorage();
        $units = new StorageUnits($storage, new UsedCarPricing($this->rules->marketPricing()));
        $kinds = [];

        foreach ($storage['units'] as $key => $kind) {
            $kinds[$key] = $this->kind($units, $key, $kind, $trials);
        }

        return [
            'about' => 'What a storage unit pays back when the find is sold at once (70% of its value, never less than the scrap of its mass), by kind of unit: the mean return as a share of the price, with the guarantee of the game and without it, and the sale in dollars at the percentiles of `percentiles`. Written by `php artisan economy:unit-returns`.',
            'trials' => $trials,
            'seed_prefix' => self::SEED_PREFIX,
            'pity_every' => $units->pityEvery(),
            'resale_ppm' => $this->rules->marketResalePpm(),
            'scrap_cents_per_kg' => $this->rules->marketScrapCentsPerKg(),
            'percentiles' => self::PERCENTILES,
            'units' => $kinds,
        ];
    }

    /** The table as the file has it. */
    public function computedJson(int $trials = self::TRIALS): string
    {
        return json_encode($this->computed($trials), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * The table of the file, as the economy simulator reads it: with the prices and the levels of the rules.
     *
     * @throws InvalidArgumentException when the file is missing, or has no kind of unit that the rules have
     */
    public function economy(): UnitEconomy
    {
        if (! is_file($this->path())) {
            throw new InvalidArgumentException("There is no table of the returns of the units at {$this->path()}. Run php artisan economy:unit-returns.");
        }

        $table = json_decode((string) file_get_contents($this->path()), true, 512, JSON_THROW_ON_ERROR);
        $kinds = [];

        foreach ($this->rules->marketStorage()['units'] as $key => $kind) {
            $row = $table['units'][$key] ?? throw new InvalidArgumentException("The table of the returns of the units has no kind {$key}. Run php artisan economy:unit-returns.");

            $kinds[$key] = [
                'unlock_level' => (int) $kind['unlock_level'],
                'price' => $kind['price_cents'] / 100,
                'mean_return' => (float) $row['mean_return'],
                'quantiles' => array_map(
                    static fn (float|int $percent, float|int $dollars): array => ['p' => (float) $percent, 'dollars' => (float) $dollars],
                    $table['percentiles'],
                    $row['sale_dollars'],
                ),
            ];
        }

        return new UnitEconomy($kinds);
    }

    /**
     * @param  array<string, mixed>  $kind  the unit of the rules
     * @return array<string, mixed>
     */
    private function kind(StorageUnits $units, string $key, array $kind, int $trials): array
    {
        $pool = $this->pool($kind['pool_level']);
        $price = $kind['price_cents'];

        $withGuarantee = $this->sales($units, $key, $pool, $trials, guarantee: true);
        $without = $this->sales($units, $key, $pool, $trials, guarantee: false);

        sort($withGuarantee);

        $last = count($withGuarantee) - 1;

        return [
            'price' => $price / 100,
            'mean_return' => round(array_sum($withGuarantee) / $trials / $price, 4),
            'mean_return_without_guarantee' => round(array_sum($without) / $trials / $price, 4),
            'sale_dollars' => array_map(
                static fn (float|int $percent): float => $withGuarantee[(int) round($percent / 100 * $last)] / 100,
                self::PERCENTILES,
            ),
        ];
    }

    /**
     * What the finds of `$trials` units of a kind are sold for, in cents, opened one after the other by one driver.
     *
     * @param  list<array{slug: string, class: string, price_cents: int, mass_kg: int}>  $pool
     * @return list<int>
     */
    private function sales(StorageUnits $units, string $key, array $pool, int $trials, bool $guarantee): array
    {
        $masses = array_column($pool, 'mass_kg', 'slug');
        $sales = [];
        $since = 0;

        for ($n = 0; $n < $trials; $n++) {
            $outcome = $units->open(self::SEED_PREFIX.'|'.($guarantee ? 'guarantee' : 'plain')."|{$key}|{$n}", $key, $pool, $guarantee ? $since : 0);

            $sales[] = max(
                $masses[$outcome->vehicle] * $this->rules->marketScrapCentsPerKg(),
                (int) round($outcome->valueCents * $this->rules->marketResalePpm() / 1_000_000),
            );

            $since = in_array($outcome->class, [StorageOutcome::GOOD, StorageOutcome::GEM], true) ? 0 : $since + 1;
        }

        return $sales;
    }

    /**
     * The cars that can be in a unit that asks this level: the combustion cars of the pack that ask it of a driver or less, in the order of the pack.
     *
     * @return list<array{slug: string, class: string, price_cents: int, mass_kg: int}>
     */
    private function pool(int $poolLevel): array
    {
        $pool = [];

        foreach ($this->catalog->vehicles() as $slug => $vehicle) {
            if ($vehicle['powertrain'] === 'ice' && $vehicle['min_player_level'] <= $poolLevel) {
                $pool[] = ['slug' => $slug, 'class' => $vehicle['class'], 'price_cents' => $vehicle['price_cents'], 'mass_kg' => $vehicle['mass_kg']];
            }
        }

        return $pool;
    }
}
