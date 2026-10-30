<?php

declare(strict_types=1);

namespace App\Filament\Resources\Definitions\Forms;

use App\Filament\Support\FieldHelp;
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
            FieldHelp::guide('vehicle'),
            Section::make(__('admin.definitions.sections.identification'))->schema([
                DefinitionFields::slug(),
                DefinitionFields::texts('name', __('admin.definitions.name'), 'definition.name'),
                DefinitionFields::texts('description', __('admin.definitions.description'), 'definition.description', long: true, required: false),
            ]),
            DefinitionFields::image(),
            Section::make(__('admin.definitions.sections.car'))->schema([
                Grid::make(3)->schema([
                    Select::make('class')->helpKey('vehicle.class')->options(self::options(self::CLASSES))->required(),
                    Select::make('drivetrain')->helpKey('vehicle.drivetrain')->options(self::options(['fwd', 'rwd', 'awd']))->required(),
                    Select::make('powertrain')->helpKey('vehicle.powertrain')->options(self::options(['ice', 'ev']))->required()->live(),
                ]),
                Grid::make(3)->schema([
                    Select::make('engine_layout')->helpKey('vehicle.engine_layout')->options(self::options(['R4', 'V6', 'R6', 'V8', 'V10', 'V12']))->visible($combustion)->required($combustion),
                    TextInput::make('engine_displacement_l')->helpKey('vehicle.engine_displacement_l')->numeric()->visible($combustion)->required($combustion),
                    TextInput::make('engine_tune')->helpKey('vehicle.engine_tune')->numeric()->visible($combustion)->required($combustion),
                    TextInput::make('engine_motors')->helpKey('vehicle.engine_motors')->numeric()->visible($electric)->required($electric),
                ]),
                Grid::make(3)->schema([
                    TextInput::make('power_kw')->helpKey('vehicle.power_kw')->numeric()->required(),
                    TextInput::make('mass_kg')->helpKey('vehicle.mass_kg')->numeric()->required(),
                    TextInput::make('top_speed_kmh')->helpKey('vehicle.top_speed_kmh')->numeric()->required(),
                    TextInput::make('clearance')->helpKey('vehicle.clearance')->numeric()->required(),
                    TextInput::make('cargo_kg')->helpKey('vehicle.cargo_kg')->numeric()->required(),
                    TextInput::make('seats')->helpKey('vehicle.seats')->numeric()->required(),
                    TextInput::make('tank_l')->helpKey('vehicle.tank_l')->numeric()->visible($combustion)->required($combustion),
                    TextInput::make('battery_kwh')->helpKey('vehicle.battery_kwh')->numeric()->visible($electric)->required($electric),
                    TextInput::make('max_charge_kw')->helpKey('vehicle.max_charge_kw')->numeric()->visible($electric)->required($electric),
                ]),
            ]),
            Section::make(__('admin.definitions.sections.offer'))->schema([
                Grid::make(3)->schema([
                    Select::make('tier')->helpKey('vehicle.tier')->options(array_combine(range(1, 10), range(1, 10)))->required(),
                    TextInput::make('min_player_level')->helpKey('vehicle.min_player_level')->numeric()->required(),
                    TextInput::make('price')->helpKey('vehicle.price')->numeric()->prefix('$')->required(),
                ]),
                CheckboxList::make('sources')->helpKey('vehicle.sources')->options(self::options(['start', 'exchange', 'dealer']))->required()->columns(3),
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
