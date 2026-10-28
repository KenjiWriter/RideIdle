<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Content\Catalog;
use App\Content\Rules;
use App\Domain\Run\Rarity;
use App\Models\Part;
use App\Support\I18nText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A part the driver owns: what the definition says (the name in the language of the driver, the slot, the tier, the
 * statistics) and where it is, in the inventory or on a car.
 *
 * @property-read Part $resource
 */
final class PartResource extends JsonResource
{
    /**
     * The affixes of a part for the client, in the order they were drawn. One that the pack does not know any more is left out.
     *
     * @param  array<string, int>  $affixes  by key, with the amount in ppm
     * @return list<array{key: string, name: string, effect: string, stat: string|null, value_ppm: int, percent: float}>
     */
    private static function affixes(array $affixes): array
    {
        $definitions = app(Rules::class)->affixes()['definitions'];
        $listed = [];

        foreach ($affixes as $key => $amount) {
            if (isset($definitions[$key])) {
                $listed[] = [
                    'key' => $key,
                    'name' => I18nText::from($definitions[$key]['name'])->for(),
                    'effect' => $definitions[$key]['effect'],
                    'stat' => $definitions[$key]['stat'] ?? null,
                    'value_ppm' => $amount,
                    'percent' => round($amount / 10_000, 1),
                ];
            }
        }

        return $listed;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $definition = app(Catalog::class)->part($this->resource->part_slug);

        return [
            'id' => $this->resource->id,
            ...self::describe($definition ?? ['slug' => $this->resource->part_slug, 'slot' => $this->resource->slot], Rarity::tryFrom((int) $this->resource->rarity) ?? Rarity::Common, (int) $this->resource->upgrade_level, $this->resource->conditionPpm(), (array) ($this->resource->affixes ?? [])),
            // How much of its life the part has left, in percent: the statistics above already count it.
            'condition_percent' => (int) round($this->resource->conditionPpm() / 10_000),
            // How much less the part gives for it than a new one, in percent (50% of life left is 20% less).
            'condition_loss_percent' => 100 - (int) round(app(Rules::class)->conditionStatPpm($this->resource->conditionPpm()) / 10_000),
            'mounted' => $this->resource->isMounted(),
            'car_id' => $this->resource->car_id,
        ];
    }

    /**
     * What a definition of the pack says about a part, as the client shows it. A tire also says how it grips on every surface
     * (`surfaces`), the column of the tire matrix its kind picks.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function describe(array $definition, Rarity $rarity = Rarity::Common, int $upgrade = 0, int $conditionPpm = 1_000_000, array $affixes = []): array
    {
        return [
            'slug' => $definition['slug'],
            'name' => isset($definition['name']) ? I18nText::from($definition['name'])->for() : $definition['slug'],
            'slot' => $definition['slot'],
            'tier' => $definition['tier'] ?? null,
            'price_cents' => $definition['price_cents'] ?? null,
            // What the car needs for the part (`min_cylinders`, `booster`), or null when it needs nothing.
            'requires' => $definition['requires'] ?? null,
            // An engine says what it is: its layout and its displacement in litres (the power and the mass are in `stats`). Null for every other part.
            'engine' => $definition['engine'] ?? null,
            // The rarity of the part (`common` to `legendary`, and 1 to 5), and the statistics it gives with it.
            'rarity' => $rarity->key(),
            'rarity_rank' => $rarity->value,
            // The upgrade of the part (+N), the most its rarity allows, and the statistics it gives with both.
            'upgrade' => $upgrade,
            'upgrade_max' => app(Rules::class)->upgradeMax($rarity),
            'stats' => app(Rules::class)->scaledStats($definition['stats'] ?? [], $rarity, $upgrade, $conditionPpm, $affixes),
            // The affixes of the part, as it was drawn with them: the name in the language of the driver, what it does (`stat`, `wear`, `fuel` or `reliability`), and how much, in percent.
            'affixes' => self::affixes($affixes),
            // Tires only: how they grip on every surface, which is what tells one kind from another.
            'surfaces' => isset($definition['tire_kind']) ? app(Rules::class)->tireSurfaces($definition['tire_kind']) : null,
        ];
    }
}
