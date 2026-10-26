<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Economy\CrateEconomy;
use JsonException;
use RuntimeException;

/**
 * The crates of the game as the economy simulator sees them: the rules of the shop (`shop.crates`, the tiers, the price of the crate of a slot, the odds
 * and the guarantees of the standard group) and the table of the pace of the rarities (`content/tables/rarity-pace.json`, made by `RarityPace`).
 */
final class GameCrates
{
    public function __construct(private readonly Rules $rules) {}

    /** @throws RuntimeException when the table of the pace of the rarities is missing or cannot be read */
    public function economy(): CrateEconomy
    {
        $crates = $this->rules->shopCrates();
        $table = $this->table();
        $guarantee = $crates['guarantee']['standard'];

        return new CrateEconomy(
            firstTierLevel: $crates['first_tier_level'],
            levelsPerTier: $crates['levels_per_tier'],
            priceFactorPpm: $crates['kinds']['slot']['price_ppm'],
            slotMix: array_count_values($table['cycle']),
            odds: $crates['odds']['standard'],
            floorEvery: $guarantee['every'],
            floorRarity: $guarantee['min_rarity'],
            legendaryEvery: $guarantee['legendary_every'],
            gain: array_map('floatval', $table['gain_by_crates']),
        );
    }

    /**
     * The table of the pace of the rarities.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function table(): array
    {
        $path = rtrim((string) config('ride.content_path'), '/\\').'/tables/rarity-pace.json';

        if (! is_file($path)) {
            throw new RuntimeException("There is no table of the pace of the rarities at {$path}. Make it with php artisan economy:rarity-pace.");
        }

        try {
            return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $problem) {
            throw new RuntimeException("The table of the pace of the rarities at {$path} is not JSON: {$problem->getMessage()}", 0, $problem);
        }
    }
}
