<?php

declare(strict_types=1);

namespace App\Filament\Resources\Definitions\Forms;

use App\Content\Catalog;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/** The form of a part. The statistics are lines of a name and a number, because which ones a part has depends on its slot and the schema leaves that open. */
final class PartForm
{
    /** The statistics that parts have had so far, suggested when a name is typed; any name that the schema allows can be written. */
    private const KNOWN_STATS = ['grip', 'handling', 'braking', 'clearance', 'power_mult', 'lag', 'fuel_mult', 'engine_load', 'boost_gain', 'mass_kg', 'launch', 'top_speed_cap_kmh', 'capacity_l', 'power_kw', 'engine_mass_kg'];

    /** @return list<mixed> */
    public static function schema(): array
    {
        $tires = fn (Get $get): bool => $get('slot') === 'tires';
        $engine = fn (Get $get): bool => $get('slot') === 'engine';

        return [
            Section::make(__('admin.definitions.sections.identification'))->schema([
                DefinitionFields::slug(),
                DefinitionFields::texts('name', __('admin.definitions.name')),
            ]),
            Section::make(__('admin.definitions.sections.part'))->schema([
                Grid::make(4)->schema([
                    Select::make('slot')->label(__('admin.definitions.slot'))->options(self::options(['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'exhaust', 'cooling', 'tank', 'ecu']))->required()->live(),
                    Select::make('tire_kind')->label(__('admin.definitions.tire_kind'))->options(self::options(['all_season', 'street', 'slick', 'rain', 'offroad', 'winter']))->visible($tires)->required($tires),
                    Select::make('tier')->label(__('admin.definitions.tier'))->options(array_combine(range(1, 10), range(1, 10)))->required(),
                    TextInput::make('price')->label(__('admin.definitions.price'))->numeric()->prefix('$')->required(),
                ]),
                Grid::make(2)->schema([
                    TextInput::make('engine_layout')->label(__('admin.definitions.engine_layout'))->visible($engine)->required($engine),
                    TextInput::make('engine_displacement_l')->label(__('admin.definitions.engine_displacement_l'))->numeric()->visible($engine)->required($engine),
                ]),
            ]),
            Section::make(__('admin.definitions.stats'))->schema([
                Repeater::make('stats')
                    ->hiddenLabel()
                    ->minItems(1)
                    ->addActionLabel(__('admin.definitions.stat_add'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('key')->label(__('admin.definitions.stat_key'))->datalist(self::statNames())->required()->distinct()->regex('/^[a-z][a-z0-9_]*$/'),
                        TextInput::make('value')->label(__('admin.definitions.stat_value'))->numeric()->required(),
                    ]),
            ]),
            Section::make(__('admin.definitions.sections.requirements'))->schema([
                Grid::make(3)->schema([
                    TextInput::make('requires_min_cylinders')->label(__('admin.definitions.requires_min_cylinders'))->numeric(),
                    Toggle::make('requires_booster')->label(__('admin.definitions.requires_booster')),
                    Select::make('fits_powertrain')->label(__('admin.definitions.fits_powertrain'))->options(self::options(['ice', 'ev']))->placeholder('-'),
                ]),
                CheckboxList::make('fits_classes')->label(__('admin.definitions.fits_classes'))->options(self::options(['hatchback', 'hot_hatch', 'sedan', 'coupe', 'muscle', 'pickup', 'van', 'gt', 'super', 'hyper']))->columns(5),
            ])->collapsed(),
        ];
    }

    /** @return list<string> */
    private static function statNames(): array
    {
        $names = self::KNOWN_STATS;

        foreach (app(Catalog::class)->parts() as $part) {
            array_push($names, ...array_keys($part['stats'] ?? []));
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private static function options(array $values): array
    {
        return array_combine($values, $values);
    }
}
