<?php

declare(strict_types=1);

namespace App\Content\Definitions;

/**
 * What the world page holds and what it does to the rules: the cities (the regions of the rules), the roads between them and the tracks, as the state of the form
 * (lists of lines, the reward in dollars, the weather and the demands as lines of a name and a number) and back into the sections of the document, in the order of
 * the file. Everything else in the document is left as it is.
 */
final class WorldForm
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array{regions: list<array<string, mixed>>, roads: list<array<string, mixed>>, tracks: list<array<string, mixed>>}
     */
    public static function toState(array $rules): array
    {
        $regions = [];

        foreach ($rules['regions'] ?? [] as $slug => $region) {
            $regions[] = [
                'slug' => $slug,
                'name' => DefinitionForm::texts($region['name'] ?? []),
                'map' => ['x' => $region['map']['x'] ?? null, 'y' => $region['map']['y'] ?? null],
                'climate_seed' => $region['climate_seed'] ?? null,
                'weather' => self::lines($region['weather_ppm'] ?? [], 'weather', 'ppm'),
            ];
        }

        $tracks = [];

        foreach ($rules['tracks'] ?? [] as $slug => $track) {
            $tracks[] = [
                'slug' => $slug,
                'name' => DefinitionForm::texts($track['name'] ?? []),
                'region' => $track['region'] ?? null,
                'surface' => $track['surface'] ?? null,
                'kind' => $track['kind'] ?? null,
                'reference_tires' => $track['reference_tires'] ?? null,
                'lit' => (bool) ($track['lit'] ?? false),
                'min_player_level' => $track['min_player_level'] ?? null,
                'length_m' => $track['length_m'] ?? null,
                'base_lap_ms' => $track['base_lap_ms'] ?? null,
                'fuel_per_lap_ml' => $track['fuel_per_lap_ml'] ?? null,
                'reward' => isset($track['reward_cents_per_lap']) ? $track['reward_cents_per_lap'] / 100 : null,
                'xp_per_lap' => $track['xp_per_lap'] ?? null,
                'demand' => self::lines($track['demand'] ?? [], 'stat', 'value'),
                'reference' => self::lines($track['reference'] ?? [], 'stat', 'value'),
            ];
        }

        return [
            'regions' => $regions,
            'roads' => array_map(fn (array $road): array => ['from' => $road['from'] ?? null, 'to' => $road['to'] ?? null, 'km' => $road['km'] ?? null], $rules['roads'] ?? []),
            'tracks' => $tracks,
        ];
    }

    /**
     * The rules with the cities, the roads and the tracks of a form.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function apply(array $rules, array $state): array
    {
        $regions = [];

        foreach ((array) ($state['regions'] ?? []) as $line) {
            $slug = DefinitionForm::text($line['slug'] ?? null);

            if ($slug === null) {
                continue;
            }

            $regions[$slug] = [
                'name' => DefinitionForm::i18n($line['name'] ?? []),
                'map' => ['x' => DefinitionForm::number($line['map']['x'] ?? null), 'y' => DefinitionForm::number($line['map']['y'] ?? null)],
                'climate_seed' => DefinitionForm::text($line['climate_seed'] ?? null),
                'weather_ppm' => self::object((array) ($line['weather'] ?? []), 'weather', 'ppm'),
            ];
        }

        $tracks = [];

        foreach ((array) ($state['tracks'] ?? []) as $line) {
            $slug = DefinitionForm::text($line['slug'] ?? null);

            if ($slug === null) {
                continue;
            }

            $track = ['name' => DefinitionForm::i18n($line['name'] ?? []), 'region' => DefinitionForm::text($line['region'] ?? null), 'surface' => DefinitionForm::text($line['surface'] ?? null)];

            if (DefinitionForm::text($line['kind'] ?? null) !== null) {
                $track['kind'] = DefinitionForm::text($line['kind']);
            }

            if (DefinitionForm::text($line['reference_tires'] ?? null) !== null) {
                $track['reference_tires'] = DefinitionForm::text($line['reference_tires']);
            }

            $track['lit'] = ! empty($line['lit']);

            foreach (['min_player_level', 'length_m', 'base_lap_ms', 'fuel_per_lap_ml'] as $field) {
                $track[$field] = DefinitionForm::number($line[$field] ?? null);
            }

            $track['reward_cents_per_lap'] = DefinitionForm::cents($line['reward'] ?? null);
            $track['xp_per_lap'] = DefinitionForm::number($line['xp_per_lap'] ?? null);
            $track['demand'] = self::object((array) ($line['demand'] ?? []), 'stat', 'value');
            $track['reference'] = self::object((array) ($line['reference'] ?? []), 'stat', 'value');

            $tracks[$slug] = $track;
        }

        $roads = [];

        foreach ((array) ($state['roads'] ?? []) as $line) {
            $roads[] = ['from' => DefinitionForm::text($line['from'] ?? null), 'to' => DefinitionForm::text($line['to'] ?? null), 'km' => DefinitionForm::number($line['km'] ?? null)];
        }

        $rules['regions'] = $regions;
        $rules['roads'] = $roads;
        $rules['tracks'] = $tracks;

        return $rules;
    }

    /**
     * An object of numbers as the lines of a form.
     *
     * @param  array<string, mixed>  $object
     * @return list<array<string, mixed>>
     */
    private static function lines(array $object, string $keyField, string $valueField): array
    {
        $lines = [];

        foreach ($object as $key => $value) {
            $lines[] = [$keyField => $key, $valueField => $value];
        }

        return $lines;
    }

    /**
     * The lines of a form as an object of numbers, in the order of the lines (a line with no name is left out).
     *
     * @param  array<int|string, mixed>  $lines
     * @return array<string, mixed>
     */
    private static function object(array $lines, string $keyField, string $valueField): array
    {
        $object = [];

        foreach ($lines as $line) {
            $key = DefinitionForm::text($line[$keyField] ?? null);

            if ($key !== null) {
                $object[$key] = DefinitionForm::number($line[$valueField] ?? null);
            }
        }

        return $object;
    }
}
