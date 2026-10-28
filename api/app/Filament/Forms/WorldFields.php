<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Content\Definitions\SchemaChoices;
use App\Filament\Forms\Components\MapPoint;
use App\Filament\Resources\Definitions\Forms\DefinitionFields;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The world of the rules as a form: the cities (with their place on the map and their weather), the roads between them, and the tracks. What the schema and the
 * rules of the world ask is theirs to say, and they say it when the draft is saved.
 */
final class WorldFields
{
    private const SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * @param  list<string>  $weather  the weather that the rules know
     * @return list<mixed>
     */
    public static function schema(array $weather): array
    {
        return [
            Tabs::make('world')->persistTabInQueryString()->tabs([
                Tab::make(__('admin.world.cities'))->schema(self::cities($weather)),
                Tab::make(__('admin.world.roads'))->schema(self::roads()),
                Tab::make(__('admin.world.tracks'))->schema(self::tracks()),
            ]),
        ];
    }

    /**
     * @param  list<string>  $weather
     * @return list<mixed>
     */
    private static function cities(array $weather): array
    {
        return [
            Repeater::make('regions')
                ->hiddenLabel()
                ->helperText(__('admin.world.cities_hint'))
                ->minItems(1)
                ->collapsible()
                ->collapsed()
                ->reorderableWithButtons()
                ->addActionLabel(__('admin.world.city_add'))
                ->itemLabel(fn (array $state): ?string => self::itemLabel($state))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('slug')->label(__('admin.definitions.slug'))->required()->maxLength(64)->regex(self::SLUG)->distinct()->live(onBlur: true),
                        TextInput::make('climate_seed')->label(__('admin.world.climate_seed'))->helperText(__('admin.world.climate_seed_hint'))->required()->maxLength(64),
                    ]),
                    DefinitionFields::texts('name', __('admin.definitions.name')),
                    MapPoint::make('map')
                        ->label(__('admin.world.map'))
                        ->helperText(__('admin.world.map_hint'))
                        ->context(fn (Get $get): array => self::board($get('../../regions'), $get('../../roads'), $get('slug'))),
                    Repeater::make('weather')
                        ->label(__('admin.world.weather'))
                        ->helperText(__('admin.world.weather_hint'))
                        ->minItems(1)
                        ->addActionLabel(__('admin.world.weather_add'))
                        ->columns(2)
                        ->schema([
                            Select::make('weather')->label(__('admin.world.weather_kind'))->options(array_combine($weather, $weather))->required()->distinct(),
                            TextInput::make('ppm')->label(__('admin.world.ppm'))->numeric()->integer()->minValue(1)->maxValue(1_000_000)->required(),
                        ]),
                ]),
        ];
    }

    /** @return list<mixed> */
    private static function roads(): array
    {
        $cities = fn (Get $get): array => ContentOptions::cities($get('../../regions'));

        return [
            Repeater::make('roads')
                ->hiddenLabel()
                ->helperText(__('admin.world.roads_hint'))
                ->minItems(1)
                ->addActionLabel(__('admin.world.road_add'))
                ->columns(3)
                ->schema([
                    Select::make('from')->label(__('admin.world.from'))->options($cities)->required(),
                    Select::make('to')->label(__('admin.world.to'))->options($cities)->required(),
                    TextInput::make('km')->label(__('admin.world.km'))->numeric()->integer()->minValue(1)->required(),
                ]),
        ];
    }

    /** @return list<mixed> */
    private static function tracks(): array
    {
        $stats = SchemaChoices::stats();

        return [
            Repeater::make('tracks')
                ->hiddenLabel()
                ->helperText(__('admin.world.tracks_hint'))
                ->minItems(1)
                ->collapsible()
                ->collapsed()
                ->reorderableWithButtons()
                ->addActionLabel(__('admin.world.track_add'))
                ->itemLabel(fn (array $state): ?string => self::itemLabel($state))
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('slug')->label(__('admin.definitions.slug'))->required()->maxLength(64)->regex(self::SLUG)->distinct(),
                        Select::make('region')->label(__('admin.world.city'))->options(fn (Get $get): array => ContentOptions::cities($get('../../regions')))->required(),
                        Select::make('surface')->label(__('admin.world.surface'))->options(['asphalt' => 'asphalt', 'gravel' => 'gravel'])->required(),
                    ]),
                    DefinitionFields::texts('name', __('admin.definitions.name')),
                    Grid::make(3)->schema([
                        Select::make('reference_tires')->label(__('admin.world.reference_tires'))->helperText(__('admin.world.reference_tires_hint'))->options(fn (): array => ContentOptions::parts('tires'))->placeholder('-'),
                        TextInput::make('min_player_level')->label(__('admin.world.min_player_level'))->numeric()->integer()->minValue(1)->required(),
                        Toggle::make('lit')->label(__('admin.world.lit'))->inline(false),
                    ]),
                    Grid::make(3)->schema([
                        TextInput::make('length_m')->label(__('admin.world.length_m'))->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('base_lap_ms')->label(__('admin.world.base_lap_ms'))->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('fuel_per_lap_ml')->label(__('admin.world.fuel_per_lap_ml'))->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('reward')->label(__('admin.world.reward'))->numeric()->prefix('$')->minValue(0.01)->required(),
                        TextInput::make('xp_per_lap')->label(__('admin.world.xp_per_lap'))->numeric()->integer()->minValue(0)->required(),
                    ]),
                    self::stats('demand', __('admin.world.demand'), __('admin.world.demand_hint'), $stats),
                    self::stats('reference', __('admin.world.reference'), __('admin.world.reference_hint'), $stats),
                ]),
        ];
    }

    /**
     * Lines of a statistic and a number.
     *
     * @param  list<string>  $stats
     */
    private static function stats(string $field, string $label, string $hint, array $stats): Repeater
    {
        return Repeater::make($field)
            ->label($label)
            ->helperText($hint)
            ->minItems(1)
            ->addActionLabel(__('admin.definitions.stat_add'))
            ->columns(2)
            ->schema([
                Select::make('stat')->label(__('admin.definitions.stat_key'))->options(array_combine($stats, $stats))->required()->distinct(),
                TextInput::make('value')->label(__('admin.definitions.stat_value'))->numeric()->required(),
            ]);
    }

    /**
     * What the board of the map shows besides the city that is being placed: the other cities, and the roads between them.
     *
     * @param  array<int|string, mixed>|null  $regions
     * @param  array<int|string, mixed>|null  $roads
     * @return array{cities: array<string, array{x: int, y: int, name: string}>, roads: list<array{from: string, to: string}>, own: string|null}
     */
    private static function board(?array $regions, ?array $roads, mixed $own): array
    {
        $cities = [];

        foreach ($regions ?? [] as $region) {
            $slug = is_string($region['slug'] ?? null) ? trim($region['slug']) : '';
            $x = $region['map']['x'] ?? null;
            $y = $region['map']['y'] ?? null;

            if ($slug !== '' && $slug !== $own && is_numeric($x) && is_numeric($y)) {
                $cities[$slug] = ['x' => (int) $x, 'y' => (int) $y, 'name' => ContentOptions::name($region['name'] ?? [], $slug)];
            }
        }

        $lines = [];

        foreach ($roads ?? [] as $road) {
            if (is_string($road['from'] ?? null) && is_string($road['to'] ?? null)) {
                $lines[] = ['from' => $road['from'], 'to' => $road['to']];
            }
        }

        return ['cities' => $cities, 'roads' => $lines, 'own' => is_string($own) && $own !== '' ? $own : null];
    }

    /** @param  array<string, mixed>  $state */
    private static function itemLabel(array $state): ?string
    {
        $slug = is_string($state['slug'] ?? null) ? trim($state['slug']) : '';

        return $slug === '' ? null : ContentOptions::name($state['name'] ?? [], $slug).' ('.$slug.')';
    }
}
