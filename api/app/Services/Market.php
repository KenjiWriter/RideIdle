<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Domain\Market\ExchangeListings;
use App\Domain\Market\StorageOutcome;
use App\Domain\Market\StorageUnits;
use App\Domain\Market\UsedCarPricing;
use App\Domain\Market\UsedListing;
use App\Exceptions\RuleRefusedException;
use App\Models\Car;
use App\Models\LedgerEntry;
use App\Models\StorageOpening;
use App\Support\I18nText;
use Illuminate\Support\Facades\DB;

/**
 * The market of the city where the car the driver plays stands (the driver has no place of their own): the used-car exchange, the dealer of
 * new cars and the yard of wrecks (Systemy III, point 11). A car that is bought stands in the garage of that city, so the driver needs a
 * garage there with a free place, and the price is taken from the wallet in the same transaction that makes the car.
 *
 * Nothing about the exchange is stored but what the driver did to it: an inspection and a purchase are ledger entries with a key made of the
 * driver and the listing, so they cannot happen twice, and the listing that was bought is not listed again.
 */
final class Market
{
    private readonly ExchangeListings $exchange;

    private readonly StorageUnits $storage;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Rules $rules,
        private readonly Catalog $catalog,
        private readonly Garage $garage,
        private readonly CityGarages $garages,
        private readonly CarSales $sales,
    ) {
        $pricing = new UsedCarPricing($rules->marketPricing());

        $this->exchange = new ExchangeListings($rules->marketExchange(), $pricing);
        $this->storage = new StorageUnits($rules->marketStorage(), $pricing);
    }

    /** The city whose market the driver sees: the one the car they play stands in. */
    public function cityOf(int $userId): string
    {
        $car = $this->garage->carOf($userId);
        $run = $car === null ? null : $this->garage->runOf($car);

        return $run === null ? $this->rules->homeRegion() : $this->garage->cityOf($run);
    }

    /**
     * Everything the screens of the market show: the exchange with the listings that are on, the dealer, the wrecks and the garage the cars
     * go to.
     *
     * @return array<string, mixed>
     */
    public function view(int $userId, int $nowMs): array
    {
        $region = $this->cityOf($userId);
        $level = $this->levelOf($userId);
        $window = $this->exchange->window($nowMs);
        $listings = $this->exchange->listings($this->seed($userId), $region, $window, $this->exchangePool($level));

        $done = $this->done($userId, $listings);
        $listings = array_values(array_filter($listings, fn (UsedListing $listing): bool => ! isset($done["listing:{$userId}:{$listing->key}"])));

        $summary = $this->garages->summary($userId)[$region];

        return [
            'city' => ['slug' => $region, 'name' => $this->rules->cityName($region)],
            'level' => $level,
            'garage' => ['owned' => $summary['owned'], 'capacity' => $summary['capacity'], 'used' => $summary['used'], 'free' => $summary['free']],
            'exchange' => [
                'refresh_ms' => $this->rules->marketExchange()['refresh_ms'],
                'next_refresh_ms' => $this->exchange->nextRefreshMs($nowMs),
                'listings' => array_map(fn (UsedListing $listing): array => $this->listingData($listing, isset($done["inspect:{$userId}:{$listing->key}"])), $listings),
            ],
            'dealer' => $this->dealerData($level),
            'wrecks' => $this->garages->yard(),
            'storage' => $this->storageData($userId, $level),
        ];
    }

    /**
     * Has a car of the exchange inspected: the driver pays a share of its price and from then on sees its condition. A listing that was
     * inspected already is not charged again.
     *
     * @throws RuleRefusedException `listing_gone`
     */
    public function inspect(int $userId, string $key, int $nowMs): void
    {
        $this->ledger->ensureWallet($userId);

        DB::transaction(function () use ($userId, $key, $nowMs): void {
            $this->ledger->lockWallet($userId);

            $listing = $this->listing($userId, $key, $nowMs);

            // Posting with the same key twice is the same posting, so a second request pays nothing.
            $this->ledger->post($userId, -$this->exchange->inspectionCostCents($listing->priceCents), 'inspection', "inspect:{$userId}:{$listing->key}");
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * Buys a car of the exchange into the garage of the city: it is the car the listing described, in the condition it really has. Returns
     * null for a car that was bought already.
     *
     * @throws RuleRefusedException `listing_gone`, `no_garage`, `garage_full`
     */
    public function buyListing(int $userId, string $key, int $nowMs): ?Car
    {
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $key, $nowMs): ?Car {
            $this->ledger->lockWallet($userId);

            if (LedgerEntry::query()->where('idem_key', "listing:{$userId}:{$key}")->exists()) {
                return null;
            }

            $listing = $this->listing($userId, $key, $nowMs);

            $this->garages->assertRoomIn($userId, $listing->region);
            $this->ledger->post($userId, -$listing->priceCents, 'exchange', "listing:{$userId}:{$listing->key}");

            return $this->garages->park($userId, $listing->vehicle, $listing->region, $nowMs, $listing->conditionPpm, $listing->priceCents);
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * Buys a new car from the dealer into the garage of the city, in a perfect condition. `$requestId` makes a repeated request harmless: one
     * that was paid already buys nothing again and answers null.
     *
     * @throws RuleRefusedException `vehicle_not_for_sale`, `vehicle_level_required`, `no_garage`, `garage_full`
     */
    public function buyNew(int $userId, string $vehicle, string $requestId, ?int $nowMs = null): ?Car
    {
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $vehicle, $requestId, $nowMs): ?Car {
            $this->ledger->lockWallet($userId);

            $key = "dealer:{$userId}:{$requestId}";

            if (LedgerEntry::query()->where('idem_key', $key)->exists()) {
                return null;
            }

            $definition = $this->catalog->vehicle($vehicle);

            if ($definition === null || ! in_array('dealer', $definition['sources'], true) || $definition['powertrain'] !== 'ice') {
                throw new RuleRefusedException('vehicle_not_for_sale');
            }

            $level = $this->levelOf($userId);

            if ($level < $definition['min_player_level']) {
                throw new RuleRefusedException('vehicle_level_required', ['required' => $definition['min_player_level'], 'current' => $level]);
            }

            $region = $this->cityOf($userId);

            $this->garages->assertRoomIn($userId, $region);
            $this->ledger->post($userId, -$definition['price_cents'], 'dealer', $key);

            return $this->garages->park($userId, $vehicle, $region, $nowMs, valueCents: $definition['price_cents']);
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * Opens a storage unit into the garage of the city: the car in it is drawn by the server, from a seed that is made of the secret of the
     * application, the driver, the kind of unit and the number of the opening, so that nobody can know it before or fish for it. The draw, with
     * what came out, is written down. `$requestId` makes a repeated request harmless: one that was paid already opens nothing and answers null.
     *
     * @return array{car: Car, outcome: StorageOutcome, sale_cents: int}|null
     *
     * @throws RuleRefusedException `unit_not_found`, `unit_level_required`, `no_garage`, `garage_full`
     */
    public function openStorage(int $userId, string $unit, string $requestId, ?int $nowMs = null): ?array
    {
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $unit, $requestId, $nowMs): ?array {
            $this->ledger->lockWallet($userId);

            $key = "storage:{$userId}:{$requestId}";

            if (LedgerEntry::query()->where('idem_key', $key)->exists()) {
                return null;
            }

            $kind = $this->storage->units()[$unit] ?? throw new RuleRefusedException('unit_not_found');
            $level = $this->levelOf($userId);

            if ($level < $kind['unlock_level']) {
                throw new RuleRefusedException('unit_level_required', ['required' => $kind['unlock_level'], 'current' => $level]);
            }

            $region = $this->cityOf($userId);

            $this->garages->assertRoomIn($userId, $region);
            $this->ledger->post($userId, -$kind['price_cents'], 'storage', $key);

            $index = StorageOpening::query()->where('user_id', $userId)->where('unit', $unit)->count();
            $seed = hash_hmac('sha256', "storage|{$userId}|{$unit}|{$index}", (string) config('app.key'));
            $outcome = $this->storage->open($seed, $unit, $this->storagePool($kind['pool_level']), $this->openingsSinceGood($userId, $unit));

            $car = $this->garages->park($userId, $outcome->vehicle, $region, $nowMs, $outcome->conditionPpm, $outcome->valueCents);

            StorageOpening::query()->create([
                'user_id' => $userId,
                'unit' => $unit,
                'opening_index' => $index,
                'outcome' => $outcome->class,
                'vehicle_slug' => $outcome->vehicle,
                'age_years' => $outcome->ageYears,
                'mileage_km' => $outcome->mileageKm,
                'condition_ppm' => $outcome->conditionPpm,
                'value_cents' => $outcome->valueCents,
                'price_cents' => $kind['price_cents'],
                'car_id' => $car->id,
            ]);

            return ['car' => $car, 'outcome' => $outcome, 'sale_cents' => $this->sales->saleCents($outcome->vehicle, $outcome->valueCents)];
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * The cars that can be in a unit: the ones of the pack that ask a level or less of a driver, in a fixed order.
     *
     * @return list<array{slug: string, class: string, price_cents: int}>
     */
    private function storagePool(int $poolLevel): array
    {
        $pool = [];

        foreach ($this->catalog->vehicles() as $slug => $vehicle) {
            if ($vehicle['powertrain'] === 'ice' && $vehicle['min_player_level'] <= $poolLevel) {
                $pool[] = ['slug' => $slug, 'class' => $vehicle['class'], 'price_cents' => $vehicle['price_cents']];
            }
        }

        return $pool;
    }

    /** How many units of a kind the driver has opened since the last that gave a good car or better: for the guarantee. */
    private function openingsSinceGood(int $userId, string $unit): int
    {
        $query = StorageOpening::query()->where('user_id', $userId)->where('unit', $unit);
        $lastGood = (clone $query)->whereIn('outcome', [StorageOutcome::GOOD, StorageOutcome::GEM])->max('opening_index');

        return $lastGood === null ? $query->count() : (clone $query)->where('opening_index', '>', $lastGood)->count();
    }

    /**
     * The storage units for the screen: what each costs, the odds, and how far the driver is from the guarantee.
     *
     * @return array<string, mixed>
     */
    private function storageData(int $userId, int $level): array
    {
        $units = [];

        foreach ($this->storage->units() as $key => $kind) {
            $units[] = [
                'key' => $key,
                'unlock_level' => $kind['unlock_level'],
                'open' => $level >= $kind['unlock_level'],
                'price_cents' => $kind['price_cents'],
                'odds' => $this->storage->oddsPercent($key),
                'until_guarantee' => max(1, $this->storage->pityEvery() - $this->openingsSinceGood($userId, $key)),
            ];
        }

        return ['pity_every' => $this->storage->pityEvery(), 'units' => $units];
    }

    private function levelOf(int $userId): int
    {
        return $this->rules->playerLevelAt($this->ledger->xp($userId));
    }

    private function seed(int $userId): string
    {
        return "market:{$userId}";
    }

    /**
     * The cars of the pack that may be on the exchange of a driver of this level, in a fixed order.
     *
     * @return list<array{slug: string, class: string, price_cents: int}>
     */
    private function exchangePool(int $level): array
    {
        $pool = [];

        foreach ($this->catalog->vehicles() as $slug => $vehicle) {
            if (in_array('exchange', $vehicle['sources'], true) && $vehicle['powertrain'] === 'ice' && $vehicle['min_player_level'] <= $level) {
                $pool[] = ['slug' => $slug, 'class' => $vehicle['class'], 'price_cents' => $vehicle['price_cents']];
            }
        }

        return $pool;
    }

    /**
     * The listing a key names, as it is now: a listing that is gone (the exchange was refreshed, the key is not one of this driver's, the car
     * was bought) and one of another city than the driver is in are both gone for the driver.
     *
     * @throws RuleRefusedException `listing_gone`
     */
    private function listing(int $userId, string $key, int $nowMs): UsedListing
    {
        $listing = $this->exchange->find($key, $this->seed($userId), $nowMs, $this->exchangePool($this->levelOf($userId)));

        if ($listing === null || $listing->region !== $this->cityOf($userId) || LedgerEntry::query()->where('idem_key', "listing:{$userId}:{$listing->key}")->exists()) {
            throw new RuleRefusedException('listing_gone');
        }

        return $listing;
    }

    /**
     * What the driver has done to these listings: the keys of their inspections and purchases that exist.
     *
     * @param  list<UsedListing>  $listings
     * @return array<string, true>
     */
    private function done(int $userId, array $listings): array
    {
        $keys = [];

        foreach ($listings as $listing) {
            $keys[] = "inspect:{$userId}:{$listing->key}";
            $keys[] = "listing:{$userId}:{$listing->key}";
        }

        return $keys === [] ? [] : array_fill_keys(LedgerEntry::query()->whereIn('idem_key', $keys)->pluck('idem_key')->all(), true);
    }

    /** @return array<string, mixed> */
    private function listingData(UsedListing $listing, bool $inspected): array
    {
        return [
            'key' => $listing->key,
            'vehicle' => $this->vehicleData($listing->vehicle),
            'age_years' => $listing->ageYears,
            'mileage_km' => $listing->mileageKm,
            'word' => $listing->word,
            'price_cents' => $listing->priceCents,
            'inspected' => $inspected,
            'inspection_cost_cents' => $inspected ? null : $this->exchange->inspectionCostCents($listing->priceCents),
            // The truth, and only once it is paid for.
            'condition' => $inspected ? ['percent' => (int) round($listing->conditionPpm / 10_000), 'defects' => $listing->defects] : null,
        ];
    }

    /** @return list<array<string, mixed>> the cars the dealer sells, the cheapest level first */
    private function dealerData(int $level): array
    {
        $offers = [];

        foreach ($this->catalog->vehicles() as $slug => $vehicle) {
            if (in_array('dealer', $vehicle['sources'], true) && $vehicle['powertrain'] === 'ice') {
                $offers[] = [
                    'vehicle' => $this->vehicleData($slug),
                    'price_cents' => $vehicle['price_cents'],
                    'level_required' => $vehicle['min_player_level'],
                    'open' => $level >= $vehicle['min_player_level'],
                ];
            }
        }

        usort($offers, fn (array $a, array $b): int => [$a['level_required'], $a['price_cents'], $a['vehicle']['slug']] <=> [$b['level_required'], $b['price_cents'], $b['vehicle']['slug']]);

        return $offers;
    }

    /** @return array<string, mixed> the body of a car for a screen: its name, class, engine, power, speed and mass */
    public function vehicleOf(string $slug): array
    {
        return $this->vehicleData($slug);
    }

    /** @return array<string, mixed> */
    private function vehicleData(string $slug): array
    {
        $vehicle = $this->catalog->vehicle($slug);

        return [
            'slug' => $slug,
            'name' => I18nText::from($vehicle['name'])->for(),
            'class' => $vehicle['class'],
            'drivetrain' => $vehicle['drivetrain'],
            'engine' => "{$vehicle['engine']['layout']} ".number_format($vehicle['engine']['displacement_l'], 1, '.', ''),
            'power_kw' => $vehicle['power_kw'],
            'top_speed_kmh' => $vehicle['top_speed_kmh'],
            'mass_kg' => $vehicle['mass_kg'],
        ];
    }
}
