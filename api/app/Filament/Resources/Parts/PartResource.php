<?php

declare(strict_types=1);

namespace App\Filament\Resources\Parts;

use App\Filament\Resources\Definitions\DefinitionResource;
use App\Filament\Resources\Definitions\Forms\PartForm;
use App\Filament\Resources\Parts\Pages\CreatePart;
use App\Filament\Resources\Parts\Pages\EditPart;
use App\Filament\Resources\Parts\Pages\ListParts;
use App\Models\ContentDefinition;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

/** The parts of the game, as drafts: what is in the packs is changed here and reaches the game with the next pack that is made live. */
class PartResource extends DefinitionResource
{
    protected static ?string $slug = 'parts';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    public static function kind(): string
    {
        return ContentDefinition::KIND_PART;
    }

    public static function getModelLabel(): string
    {
        return __('admin.definitions.part.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.definitions.part.many');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(PartForm::schema());
    }

    protected static function kindColumns(): array
    {
        return [
            TextColumn::make('slot')->label(__('admin.definitions.slot'))->state(fn (ContentDefinition $record): string => (string) ($record->workingDocument()['slot'] ?? '')),
            TextColumn::make('tier')->label(__('admin.definitions.tier'))->state(fn (ContentDefinition $record): string => (string) ($record->workingDocument()['tier'] ?? '')),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListParts::route('/'),
            'create' => CreatePart::route('/create'),
            'edit' => EditPart::route('/{record}/edit'),
        ];
    }
}
