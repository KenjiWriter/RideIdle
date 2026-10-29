<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\View;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;

/**
 * A form that says what the person has changed and has not saved yet. The bar is drawn in the browser from the form as it is typed (nothing is asked of the server for it):
 * each field that is not what was saved shows what it was and what it is, with a button to put it back, and the line of what the change does where there is one.
 *
 * The page calls `rememberSavedState()` whenever the form has been filled or saved, and puts `changesBar()` in what it shows.
 */
trait ShowsChanges
{
    /** @var array<string, mixed> the values of the form as they were saved, in one list with the keys joined by dots */
    public array $changesBaseline = [];

    /** A number that changes with every baseline, so that the bar is made again from it. */
    public int $changesVersion = 0;

    /** Takes the form as it is now for what was saved. */
    protected function rememberSavedState(): void
    {
        $this->changesBaseline = array_filter(Arr::dot($this->data ?? []), static fn (mixed $value): bool => is_scalar($value) || $value === null);
        $this->changesVersion++;
    }

    /** The bar, to be put above the form. */
    protected function changesBar(): View
    {
        return View::make('filament.changes.bar')->viewData(fn (): array => $this->changesViewData());
    }

    /**
     * The fields that only move about in the form (choosing the section that is shown, a search) and are not part of what is saved: they are left out of the bar.
     *
     * @return list<string>
     */
    protected function changesIgnoredFields(): array
    {
        return [];
    }

    /**
     * The group of lines that say what a change of a field does, in the lang files of the panel (`admin.changes.effects.{group}.{field}`); none by default.
     */
    protected function changesEffectsGroup(): ?string
    {
        return null;
    }

    /**
     * @return array{version: int, baseline: array<string, mixed>, fields: array<string, array{label: string, kind: string, options: array<string, string>|null, effect: string|null}>}
     */
    public function changesViewData(): array
    {
        $fields = [];
        $group = $this->changesEffectsGroup();

        foreach ($this->form->getFlatFields() as $field) {
            $path = $field->getStatePath(isAbsolute: false);
            $label = $field->getLabel();
            $label = $label instanceof Htmlable ? strip_tags($label->toHtml()) : (string) $label;

            if ($path === '' || $label === '' || in_array($path, $this->changesIgnoredFields(), true) || ! array_key_exists($path, $this->changesBaseline)) {
                continue;
            }

            $options = null;

            if ($field instanceof Select && ! $field->isMultiple() && ! $field->isSearchable()) {
                $options = array_map(static fn (mixed $option): string => (string) $option, $field->getOptions());
            }

            $key = $group === null ? null : "admin.changes.effects.{$group}.{$path}";

            $fields[$path] = [
                'label' => $label,
                'kind' => $field instanceof Toggle ? 'bool' : 'text',
                'options' => $options,
                'effect' => $key !== null && Lang::has($key) ? (string) __($key) : null,
            ];
        }

        return ['version' => $this->changesVersion, 'baseline' => $this->changesBaseline, 'fields' => $fields];
    }
}
