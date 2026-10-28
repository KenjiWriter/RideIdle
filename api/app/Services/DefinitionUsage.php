<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\FileContentSource;
use App\Models\Car;
use App\Models\ContentDefinition;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\TutorialProgress;
use Illuminate\Support\Facades\DB;

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
     * What a new document of the rules or of the tutorial takes out that the players are using: the cities that have a garage, a workshop, a job or a journey of
     * a player, the tracks that a run drives and the steps of the tutorial that a driver has met. Taking such a thing out would leave a player in a place that
     * is not there, so it is refused; a name is for good, and changing one is taking it out. Words for the panel, by what they are.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string> empty when nothing that is used is taken out
     */
    public function removedInUse(string $slug, array $before, array $after): array
    {
        $used = [];

        if ($slug === 'starter') {
            foreach (array_diff(array_keys($before['regions'] ?? []), array_keys($after['regions'] ?? [])) as $region) {
                $count = DB::table('city_garages')->where('region', $region)->count()
                    + DB::table('city_workshops')->where('city', $region)->count()
                    + DB::table('workshop_jobs')->where('city', $region)->count()
                    + DB::table('travels')->where('from_region', $region)->orWhere('to_region', $region)->count();

                if ($count > 0) {
                    $used[] = "city {$region} ({$count})";
                }
            }

            foreach (array_diff(array_keys($before['tracks'] ?? []), array_keys($after['tracks'] ?? [])) as $track) {
                $count = RaceRun::withoutGlobalScopes()->where('track_slug', $track)->count();

                if ($count > 0) {
                    $used[] = "track {$track} ({$count})";
                }
            }
        }

        if ($slug === 'tutorial') {
            $kept = array_column($after['steps'] ?? [], 'key');

            foreach (array_diff(array_column($before['steps'] ?? [], 'key'), $kept) as $key) {
                $count = TutorialProgress::query()->where('step_key', $key)->count();

                if ($count > 0) {
                    $used[] = "step {$key} ({$count})";
                }
            }
        }

        return $used;
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
