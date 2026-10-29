<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Content\Definitions\JsonDocument;
use App\Content\Definitions\VocabularyForm;
use App\Content\Vocabulary\MessageCatalog;
use App\Filament\Forms\VocabularyFields;
use App\Models\ContentDefinition;
use App\Services\ContentDrafts;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use JsonException;
use stdClass;

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

    /**
     * A theme is a whole vocabulary in a file: the words of the game about ships, or about space. It is taken out as the file that the draft is now, and put in
     * from a file that has the same shape, in place of the words of the pack.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label(__('admin.vocabulary.download'))
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn () => response()->streamDownload(function (): void {
                    echo JsonDocument::encode($this->definition()?->workingDocument() ?: ['schema' => 0, 'version' => $this->version(), 'messages' => new stdClass]);
                }, 'vocabulary.json', ['Content-Type' => 'application/json'])),
            Action::make('import')
                ->label(__('admin.vocabulary.import'))
                ->icon('heroicon-m-arrow-up-tray')
                ->color('gray')
                ->modalHeading(__('admin.vocabulary.import_title'))
                ->modalDescription(__('admin.vocabulary.import_hint'))
                ->schema([
                    FileUpload::make('theme')
                        ->label(__('admin.vocabulary.import_file'))
                        ->acceptedFileTypes(['application/json', 'text/plain'])
                        ->maxSize(512)
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->importTheme($data['theme'])),
            ...parent::getHeaderActions(),
        ];
    }

    /**
     * Puts the words of a file in place of the words of the pack, as a draft: the file is judged like any draft is, and nothing is kept when it is not accepted.
     * The file is a vocabulary as `download` makes it; only its messages are taken.
     */
    protected function importTheme(mixed $file): void
    {
        $definition = $this->definition();

        if ($definition === null || ! is_object($file) || ! method_exists($file, 'get')) {
            return;
        }

        try {
            $messages = JsonDocument::decode((string) $file->get())['messages'] ?? null;
        } catch (JsonException) {
            $messages = null;
        }

        if (! is_array($messages) && ! $messages instanceof stdClass) {
            Notification::make()->danger()->title(__('admin.vocabulary.import_invalid'))->persistent()->send();

            return;
        }

        $this->keep($definition, ['schema' => 0, 'version' => $definition->workingDocument()['version'] ?? $this->version(), 'messages' => (array) $messages === [] ? new stdClass : $messages]);
    }

    protected function fields(array $document): array
    {
        $catalog = MessageCatalog::fromConfig();
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
            ...VocabularyFields::schema($shown['keys'], $catalog, $document, $this->vocabulary()->packSlotKeys()),
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
        return $this->vocabularyForm ??= new VocabularyForm(MessageCatalog::fromConfig(), app(ContentDrafts::class)->workingModel());
    }
}
