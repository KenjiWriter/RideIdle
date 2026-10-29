<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Content\Definitions\ModelForm;
use App\Filament\Forms\ModelFields;
use App\Models\ContentDefinition;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/**
 * The model of the game: the slots that parts go into (with the role that the formulas know each by, or none) and the statistics that the screens show. What is
 * saved is the draft of the document `rules/model`, kept only when the schema and the rules about the content accept it (a slot that a part or a rule still names
 * cannot be taken out), and it reaches the game with the next pack that is made live.
 */
class ModelPage extends RulesDocumentPage
{
    protected static ?string $slug = 'model';

    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string
    {
        return __('admin.model.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.model.title');
    }

    protected function documentSlug(): string
    {
        return 'model';
    }

    /** A database that was made before the model existed has no such document; the page shows the model of the car game, and the first draft makes the document. */
    protected function unstartedDefinition(): ?ContentDefinition
    {
        return (new ContentDefinition)->forceFill([
            'uid' => (string) Str::ulid(),
            'kind' => ContentDefinition::KIND_RULES,
            'slug' => $this->documentSlug(),
            'status' => ContentDefinition::STATUS_DRAFT,
        ]);
    }

    protected function fields(array $document): array
    {
        return ModelFields::schema();
    }

    protected function toState(array $document): array
    {
        return ModelForm::toState($document);
    }

    protected function toDocument(array $document, array $state): array
    {
        return ModelForm::apply($document, $state, $this->version());
    }

    /** The version a new document is given: the one of the rules, which is what the other documents of a pack carry. */
    private function version(): string
    {
        $rules = ContentDefinition::query()->where('kind', ContentDefinition::KIND_RULES)->where('slug', 'starter')->with('latestVersion')->first();

        return (string) ($rules?->workingDocument()['version'] ?? '2026.10.0');
    }
}
