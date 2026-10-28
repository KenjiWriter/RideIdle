<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Content\Definitions\TutorialForm;
use App\Filament\Forms\TutorialFields;
use Illuminate\Contracts\Support\Htmlable;

/** The steps of the tutorial, with the messages of the mentor and what the driver is given, and the arrangements of the first run. */
class TutorialPage extends RulesDocumentPage
{
    protected static ?string $slug = 'tutorial';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('admin.tutorial.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.tutorial.title');
    }

    protected function documentSlug(): string
    {
        return 'tutorial';
    }

    protected function fields(array $document): array
    {
        return TutorialFields::schema();
    }

    protected function toState(array $document): array
    {
        return TutorialForm::toState($document);
    }

    protected function toDocument(array $document, array $state): array
    {
        return TutorialForm::apply($document, $state);
    }
}
