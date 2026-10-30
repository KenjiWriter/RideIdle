<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Content\Definitions\WorldForm;
use App\Filament\Forms\WorldFields;
use Illuminate\Contracts\Support\Htmlable;

/** The cities (and where they are on the map), the roads between them, and the tracks: the part of the rules that makes the world. */
class WorldPage extends RulesDocumentPage
{
    protected static ?string $slug = 'world';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('admin.world.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.world.title');
    }

    protected function documentSlug(): string
    {
        return 'starter';
    }

    protected function fields(array $document): array
    {
        return WorldFields::schema(array_map('strval', array_keys($document['weather'] ?? [])), $document);
    }

    protected function toState(array $document): array
    {
        return WorldForm::toState($document);
    }

    protected function toDocument(array $document, array $state): array
    {
        return WorldForm::apply($document, $state);
    }
}
