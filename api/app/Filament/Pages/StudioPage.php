<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Content\Definitions\JsonDocument;
use App\Content\Studio\Parameter;
use App\Content\Studio\ParameterCatalog;
use App\Content\Studio\SimpleMode;
use App\Filament\Forms\StudioFields;
use App\Filament\Support\FieldHelp;
use App\Services\ContentPacks;
use App\Services\ContentPublisher;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Every number of the rules, a section at a time: sliders where the schema bounds a number, fields where it does not, each in the unit a person thinks in, with what it is for
 * and what the live pack has there. The simple mode shows only the numbers that set the pace and the money of the game (`SimpleMode`), and every section says what changing it
 * does; the advanced mode shows all of them. The numbers are found in the document by walking it with its schema (`ParameterCatalog`), so a number that the rules get later is here
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
        return ['section', 'sliders', 'simple'];
    }

    protected function fields(array $document): array
    {
        $section = $this->section($document);
        $sliders = $this->sliders();

        $options = [];

        foreach ($this->visibleSections($document) as $key => $count) {
            $options[$key] = self::sectionName($key)." ({$count})";
        }

        $effect = self::sectionEffect($section);
        $all = count($this->catalog()->parameters($document, $section));
        $shown = count($this->parameters($document, $section));

        return [
            FieldHelp::guide('studio'),
            Toggle::make('simple')
                ->helpKey('studio.simple')
                ->live()
                ->afterStateUpdated(fn () => $this->fillForm()),
            Select::make('section')
                ->helpKey('studio.section')
                ->options($options)
                ->selectablePlaceholder(false)
                ->required()
                ->live()
                ->afterStateUpdated(fn () => $this->fillForm()),
            Toggle::make('sliders')
                ->helpKey('studio.sliders')
                ->live()
                ->afterStateUpdated(fn () => $this->fillForm()),
            ...($effect === null ? [] : [Text::make($effect)]),
            ...($this->simple() ? [Text::make(__('admin.studio.simple_shown', ['shown' => $shown, 'total' => $all]))] : []),
            ...StudioFields::schema($this->parameters($document, $section), $sliders),
        ];
    }

    protected function toState(array $document): array
    {
        $section = $this->section($document);
        $values = [];

        foreach ($this->parameters($document, $section) as $position => $parameter) {
            $values["p{$position}"] = $parameter->display();
        }

        return ['section' => $section, 'simple' => $this->simple(), 'sliders' => $this->sliders(), 'values' => $values];
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

    /** The line that says what changing the numbers of a section does, when the lang files have one. */
    public static function sectionEffect(string $key): ?string
    {
        return Lang::has("admin.studio.effects.{$key}") ? (string) __("admin.studio.effects.{$key}") : null;
    }

    /**
     * The numbers of a section that are shown, at the positions they have in the whole section: all of them, or the ones of the simple mode.
     *
     * @param  array<string, mixed>  $document
     * @return array<int, Parameter>
     */
    private function parameters(array $document, string $section): array
    {
        $parameters = $this->catalog()->parameters($document, $section, $this->live());

        return $this->simple() ? $this->simpleMode()->only($parameters) : $parameters;
    }

    /**
     * The sections that have numbers to show, with how many.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, int>
     */
    private function visibleSections(array $document): array
    {
        $sections = [];

        foreach (array_keys($this->catalog()->sections($document)) as $section) {
            $count = count($this->parameters($document, $section));

            if ($count > 0) {
                $sections[$section] = $count;
            }
        }

        return $sections;
    }

    /** The simple mode is the way unless the person asked for every number. */
    private function simple(): bool
    {
        return (bool) ($this->data['simple'] ?? true);
    }

    private function simpleMode(): SimpleMode
    {
        return new SimpleMode;
    }

    /** The section that is open: the one that was chosen, and the first that has numbers to show until one is. */
    private function section(array $document): string
    {
        $sections = $this->visibleSections($document);
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
