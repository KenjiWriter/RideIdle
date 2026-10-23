<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Content\Catalog;
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
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $definition = app(Catalog::class)->part($this->resource->part_slug);

        return [
            'id' => $this->resource->id,
            ...self::describe($definition ?? ['slug' => $this->resource->part_slug, 'slot' => $this->resource->slot]),
            'mounted' => $this->resource->isMounted(),
            'car_id' => $this->resource->car_id,
        ];
    }

    /**
     * What a definition of the pack says about a part, as the client shows it.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function describe(array $definition): array
    {
        return [
            'slug' => $definition['slug'],
            'name' => isset($definition['name']) ? I18nText::from($definition['name'])->for() : $definition['slug'],
            'slot' => $definition['slot'],
            'tier' => $definition['tier'] ?? null,
            'price_cents' => $definition['price_cents'] ?? null,
            'stats' => $definition['stats'] ?? [],
        ];
    }
}
