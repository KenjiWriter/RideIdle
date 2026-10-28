<?php

declare(strict_types=1);

namespace App\Filament\Resources\Definitions\Forms;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/** The form of a car. What the schema asks of the numbers (the ranges, whole numbers) is its to say, and it says it when the draft is saved. */
final class VehicleForm
{
    private const CLASSES = ['hatchback', 'hot_hatch', 'sedan', 'coupe', 'muscle', 'pickup', 'van', 'gt', 'super', 'hyper'];

    /** @return list<mixed> */
    public static function schema(): array
    {
        $electric = fn (Get $get): bool => $get('powertrain') === 'ev';
        $combustion = fn (Get $get): bool => $get('powertrain') !== 'ev';

        return [
            Section::make(__('admin.definitions.sections.identification'))->schema([
                DefinitionFields::slug(),
                DefinitionFields::texts('name', __('admin.definitions.name')),
                DefinitionFields::texts('description', __('admin.definitions.description'), long: true, required: false),
            ]),
            Section::make(__('admin.definitions.sections.car'))->schema([
                Grid::make(3)->schema([
                    Select::make('class')->label(__('admin.definitions.class'))->options(self::options(self::CLASSES))->required(),
                    Select::make('drivetrain')->label(__('admin.definitions.drivetrain'))->options(self::options(['fwd', 'rwd', 'awd']))->required(),
                    Select::make('powertrain')->label(__('admin.definitions.powertrain'))->options(self::options(['ice', 'ev']))->required()->live(),
                ]),
                Grid::make(3)->schema([
                    Select::make('engine_layout')->label(__('admin.definitions.engine_layout'))->options(self::options(['R4', 'V6', 'R6', 'V8', 'V10', 'V12']))->visible($combustion)->required($combustion),
                    TextInput::make('engine_displacement_l')->label(__('admin.definitions.engine_displacement_l'))->numeric()->visible($combustion)->required($combustion),
                    TextInput::make('engine_tune')->label(__('admin.definitions.engine_tune'))->numeric()->visible($combustion)->required($combustion),
                    TextInput::make('engine_motors')->label(__('admin.definitions.engine_motors'))->numeric()->visible($electric)->required($electric),
                ]),
                Grid::make(3)->schema([
                    TextInput::make('power_kw')->label(__('admin.definitions.power_kw'))->numeric()->required(),
                    TextInput::make('mass_kg')->label(__('admin.definitions.mass_kg'))->numeric()->required(),
                    TextInput::make('top_speed_kmh')->label(__('admin.definitions.top_speed_kmh'))->numeric()->required(),
                    TextInput::make('clearance')->label(__('admin.definitions.clearance'))->numeric()->required(),
                    TextInput::make('cargo_kg')->label(__('admin.definitions.cargo_kg'))->numeric()->required(),
                    TextInput::make('seats')->label(__('admin.definitions.seats'))->numeric()->required(),
                    TextInput::make('tank_l')->label(__('admin.definitions.tank_l'))->numeric()->visible($combustion)->required($combustion),
                    TextInput::make('battery_kwh')->label(__('admin.definitions.battery_kwh'))->numeric()->visible($electric)->required($electric),
                    TextInput::make('max_charge_kw')->label(__('admin.definitions.max_charge_kw'))->numeric()->visible($electric)->required($electric),
                ]),
            ]),
            Section::make(__('admin.definitions.sections.offer'))->schema([
                Grid::make(3)->schema([
                    Select::make('tier')->label(__('admin.definitions.tier'))->options(array_combine(range(1, 10), range(1, 10)))->required(),
                    TextInput::make('min_player_level')->label(__('admin.definitions.min_player_level'))->helperText(__('admin.definitions.min_player_level_hint'))->numeric()->required(),
                    TextInput::make('price')->label(__('admin.definitions.price'))->numeric()->prefix('$')->required(),
                ]),
                CheckboxList::make('sources')->label(__('admin.definitions.sources'))->options(self::options(['start', 'exchange', 'dealer']))->required()->columns(3),
            ]),
        ];
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
