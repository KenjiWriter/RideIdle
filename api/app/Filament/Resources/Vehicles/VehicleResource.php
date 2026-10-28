<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vehicles;

use App\Filament\Resources\Definitions\DefinitionResource;
use App\Filament\Resources\Definitions\Forms\VehicleForm;
use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Models\ContentDefinition;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

/** The vehicles of the game, as drafts: what is in the packs is changed here and reaches the game with the next pack that is made live. */
class VehicleResource extends DefinitionResource
{
    protected static ?string $slug = 'vehicles';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    public static function kind(): string
    {
        return ContentDefinition::KIND_VEHICLE;
    }

    public static function getModelLabel(): string
    {
        return __('admin.definitions.vehicle.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.definitions.vehicle.many');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(VehicleForm::schema());
    }

    protected static function kindColumns(): array
    {
        return [
            TextColumn::make('class')->label(__('admin.definitions.class'))->state(fn (ContentDefinition $record): string => (string) ($record->workingDocument()['class'] ?? '')),
            TextColumn::make('tier')->label(__('admin.definitions.tier'))->state(fn (ContentDefinition $record): string => (string) ($record->workingDocument()['tier'] ?? '')),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehicles::route('/'),
            'create' => CreateVehicle::route('/create'),
            'edit' => EditVehicle::route('/{record}/edit'),
        ];
    }
}
