<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\FileContentSource;
use App\Models\Car;
use App\Models\ContentDefinition;
use App\Models\Part;
use App\Models\RaceRun;

/**
 * What a definition is used by: the cars, the parts and the runs of the players that are made from it, and the places in the rules and the tutorial of the pack
 * that is live that name it. A definition that is used by any of them cannot be archived: it can only be withdrawn from the offer.
 */
final class DefinitionUsage
{
    public function __construct(private readonly ContentPacks $packs) {}

    /**
     * How many things of the players are made from the definition, by what they are. The cars and runs that were sold count: the history of a player names them.
     *
     * @return array<string, int> empty when there are none
     */
    public function instances(ContentDefinition $definition): array
    {
        $counts = match ($definition->kind) {
            ContentDefinition::KIND_VEHICLE => [
                'cars' => Car::withoutGlobalScopes()->where('vehicle_slug', $definition->slug)->count(),
                'runs' => RaceRun::withoutGlobalScopes()->where('car_slug', $definition->slug)->count(),
            ],
            ContentDefinition::KIND_PART => ['parts' => Part::query()->where('part_slug', $definition->slug)->count()],
            default => [],
        };

        return array_filter($counts);
    }

    /**
     * The places of the rules and the tutorial of the pack that is live that name the definition: the stock tires, the shop, the wreck prices of the garage and the
     * parts that the tutorial gives away.
     *
     * @return list<string>
     */
    public function references(ContentDefinition $definition): array
    {
        $source = $this->packs->active() === null ? new FileContentSource((string) config('ride.content_path')) : $this->packs->activeSource();
        $rules = $source->has('rules/starter') ? $source->document('rules/starter') : [];
        $places = [];

        if ($definition->kind === ContentDefinition::KIND_PART) {
            if (($rules['stock_tires'] ?? null) === $definition->slug) {
                $places[] = 'stock_tires';
            }

            if (in_array($definition->slug, $rules['shop']['parts'] ?? [], true)) {
                $places[] = 'shop.parts';
            }

            foreach ($source->has('rules/tutorial') ? ($source->document('rules/tutorial')['steps'] ?? []) : [] as $step) {
                if (($step['reward']['part'] ?? null) === $definition->slug) {
                    $places[] = "tutorial.{$step['key']}";
                }
            }
        }

        if ($definition->kind === ContentDefinition::KIND_VEHICLE) {
            foreach (['wreck_price_cents', 'wreck_mileage_km'] as $field) {
                if (array_key_exists($definition->slug, $rules['garage'][$field] ?? [])) {
                    $places[] = "garage.{$field}";
                }
            }
        }

        return $places;
    }
}
