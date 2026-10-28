<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Content\Definitions\VocabularyForm;
use App\Content\Vocabulary\WebMessages;
use App\Filament\Forms\VocabularyFields;
use App\Models\ContentDefinition;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/**
 * The words of the game: the messages of the web app (the names of the slots and the stats, the weather, the menu, the sentences of the screens) that this pack says
 * in its own way, in the five languages. What is saved is the draft of the vocabulary of the pack, and the game speaks it once a pack that has it is live. The messages
 * are many, so a few are shown at a time: those of an area, or those that a search finds, or only the ones that were changed.
 */
class VocabularyPage extends RulesDocumentPage
{
    protected static ?string $slug = 'vocabulary';

    protected static ?int $navigationSort = 6;

    private ?VocabularyForm $vocabularyForm = null;

    public static function getNavigationLabel(): string
    {
        return __('admin.vocabulary.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.vocabulary.title');
    }

    protected function documentSlug(): string
    {
        return 'vocabulary';
    }

    /** A database that was made before the vocabulary existed has no such document; the first draft makes it. */
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
        $catalog = WebMessages::fromConfig();
        $areas = $this->vocabulary()->areas($document);
        $shown = $this->vocabulary()->visible($document, $this->area($document), $this->search(), $this->onlyChanged());

        $options = [VocabularyForm::ALL => __('admin.vocabulary.all_areas', ['count' => array_sum(array_column($areas, 'total'))])];

        foreach ($areas as $area => $count) {
            $options[$area] = __('admin.vocabulary.area_option', ['area' => $area, 'total' => $count['total'], 'changed' => $count['changed']]);
        }

        return [
            ...($catalog->available() ? [] : [Text::make(__('admin.vocabulary.no_catalog'))]),
            Select::make('area')
                ->label(__('admin.vocabulary.area'))
                ->options($options)
                ->selectablePlaceholder(false)
                ->required()
                ->live()
                ->afterStateUpdated(fn () => $this->fillForm()),
            TextInput::make('search')
                ->label(__('admin.vocabulary.search'))
                ->helperText(__('admin.vocabulary.search_hint'))
                ->live(onBlur: true)
                ->afterStateUpdated(fn () => $this->fillForm()),
            Toggle::make('only_changed')
                ->label(__('admin.vocabulary.only_changed'))
                ->live()
                ->afterStateUpdated(fn () => $this->fillForm()),
            Text::make(__('admin.vocabulary.shown', ['shown' => count($shown['keys']), 'matching' => $shown['matching']]).' '.__('admin.vocabulary.hint')),
            ...VocabularyFields::schema($shown['keys'], $catalog, $document),
        ];
    }

    protected function toState(array $document): array
    {
        $shown = $this->vocabulary()->visible($document, $this->area($document), $this->search(), $this->onlyChanged());

        return [
            'area' => $this->area($document),
            'search' => $this->search(),
            'only_changed' => $this->onlyChanged(),
            ...$this->vocabulary()->toState($document, $shown['keys']),
        ];
    }

    protected function toDocument(array $document, array $state): array
    {
        $shown = $this->vocabulary()->visible($document, (string) ($state['area'] ?? VocabularyForm::ALL), (string) ($state['search'] ?? ''), (bool) ($state['only_changed'] ?? false));

        return $this->vocabulary()->apply($document, $shown['keys'], $state, $this->version());
    }

    /**
     * The area that is open: the one that was chosen, and all of them until one is.
     *
     * @param  array<string, mixed>  $document
     */
    private function area(array $document): string
    {
        $chosen = $this->data['area'] ?? null;

        return is_string($chosen) && ($chosen === VocabularyForm::ALL || isset($this->vocabulary()->areas($document)[$chosen])) ? $chosen : VocabularyForm::ALL;
    }

    private function search(): string
    {
        return (string) ($this->data['search'] ?? '');
    }

    private function onlyChanged(): bool
    {
        return (bool) ($this->data['only_changed'] ?? false);
    }

    /** The version a new document is given: the one of the rules, which is what the other documents of a pack carry. */
    private function version(): string
    {
        $rules = ContentDefinition::query()->where('kind', ContentDefinition::KIND_RULES)->where('slug', 'starter')->with('latestVersion')->first();

        return (string) ($rules?->workingDocument()['version'] ?? '2026.10.0');
    }

    private function vocabulary(): VocabularyForm
    {
        return $this->vocabularyForm ??= new VocabularyForm(WebMessages::fromConfig());
    }
}
