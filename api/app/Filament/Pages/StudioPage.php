<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Content\Definitions\JsonDocument;
use App\Content\Studio\ParameterCatalog;
use App\Filament\Forms\StudioFields;
use App\Services\ContentPacks;
use App\Services\ContentPublisher;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Every number of the rules, a section at a time: sliders where the schema bounds a number, fields where it does not, each in the unit a person thinks in, with what it is for
 * and what the live pack has there. The numbers are found in the document by walking it with its schema (`ParameterCatalog`), so a number that the rules get later is here
 * without a page written for it. What is saved is the draft of the rules, judged as the other pages' drafts are, and it reaches the game with the next pack that is made live.
 */
class StudioPage extends RulesDocumentPage
{
    protected static ?string $slug = 'studio';

    protected static ?int $navigationSort = 5;

    private ?ParameterCatalog $catalog = null;

    /** @var array<string, mixed>|null */
    private ?array $live = null;

    private bool $liveRead = false;

    public static function getNavigationLabel(): string
    {
        return __('admin.studio.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.studio.title');
    }

    protected function documentSlug(): string
    {
        return 'starter';
    }

    protected function changesIgnoredFields(): array
    {
        return ['section', 'sliders'];
    }

    protected function fields(array $document): array
    {
        $catalog = $this->catalog();
        $section = $this->section($document);
        $sliders = $this->sliders();

        $options = [];

        foreach ($catalog->sections($document) as $key => $count) {
            $options[$key] = self::sectionName($key)." ({$count})";
        }

        return [
            Select::make('section')
                ->label(__('admin.studio.section'))
                ->helperText(__('admin.studio.section_hint'))
                ->options($options)
                ->selectablePlaceholder(false)
                ->required()
                ->live()
                ->afterStateUpdated(fn () => $this->fillForm()),
            Toggle::make('sliders')
                ->label(__('admin.studio.sliders'))
                ->helperText(__('admin.studio.sliders_hint'))
                ->live()
                ->afterStateUpdated(fn () => $this->fillForm()),
            ...StudioFields::schema($catalog->parameters($document, $section, $this->live()), $sliders),
        ];
    }

    protected function toState(array $document): array
    {
        $section = $this->section($document);
        $values = [];

        foreach ($this->catalog()->parameters($document, $section, $this->live()) as $position => $parameter) {
            $values["p{$position}"] = $parameter->display();
        }

        return ['section' => $section, 'sliders' => $this->sliders(), 'values' => $values];
    }

    protected function toDocument(array $document, array $state): array
    {
        $values = [];

        foreach ((array) ($state['values'] ?? []) as $name => $value) {
            $values[(int) substr((string) $name, 1)] = $value;
        }

        return $this->catalog()->apply($document, $this->section($document), $values);
    }

    /**
     * The preview of the balance beside what the base page has: the simulator on the saved draft and on the live pack, side by side.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('admin.studio.preview'))
                ->icon('heroicon-m-chart-bar')
                ->color('gray')
                ->modalHeading(__('admin.studio.preview_title'))
                ->modalDescription(__('admin.studio.preview_hint'))
                ->modalWidth('7xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('admin.studio.close'))
                ->modalContent(fn () => view('filament.studio.preview', ['report' => app(ContentPublisher::class)->preview()])),
            ...parent::getHeaderActions(),
        ];
    }

    /** The name of a section of the rules, in the language of the panel. */
    public static function sectionName(string $key): string
    {
        return Lang::has("admin.studio.sections.{$key}") ? (string) __("admin.studio.sections.{$key}") : Str::headline($key);
    }

    /** The section that is open: the one that was chosen, and the first that has numbers until one is. */
    private function section(array $document): string
    {
        $sections = $this->catalog()->sections($document);
        $chosen = $this->data['section'] ?? null;

        return is_string($chosen) && isset($sections[$chosen]) ? $chosen : (string) array_key_first($sections);
    }

    /** Sliders are the way unless the person asked for fields. */
    private function sliders(): bool
    {
        return (bool) ($this->data['sliders'] ?? true);
    }

    private function catalog(): ParameterCatalog
    {
        return $this->catalog ??= ParameterCatalog::ofTheGame();
    }

    /**
     * The rules of the pack that is live, for what each number was; null when there is none.
     *
     * @return array<string, mixed>|null
     */
    private function live(): ?array
    {
        if (! $this->liveRead) {
            $this->liveRead = true;
            $source = app(ContentPacks::class)->activeSource();
            $this->live = $source !== null && $source->has('rules/starter') ? JsonDocument::decode((string) $source->raw('rules/starter')) : null;
        }

        return $this->live;
    }
}
