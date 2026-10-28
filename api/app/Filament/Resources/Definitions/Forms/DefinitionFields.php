<?php

declare(strict_types=1);

namespace App\Filament\Resources\Definitions\Forms;

use App\Content\Definitions\DefinitionForm;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;

/** The parts of the forms of cars and parts that are the same: the slug and the texts in the languages of the game. */
final class DefinitionFields
{
    /** The slug is chosen when a definition is made and never changed afterwards. */
    public static function slug(): TextInput
    {
        return TextInput::make('slug')
            ->label(__('admin.definitions.slug'))
            ->helperText(__('admin.definitions.slug_hint'))
            ->required()
            ->maxLength(64)
            ->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')
            ->disabled(fn (?object $record): bool => $record !== null)
            ->dehydrated();
    }

    /**
     * A text in every language of the game: English and Polish are required, the others fall back to English, which the validation warns about.
     *
     * @param  string  $field  where the texts are in the state (the languages themselves when it is empty, for a list of texts)
     * @param  bool  $long  a box for a paragraph and not a line
     */
    public static function texts(string $field, string $label, bool $long = false, bool $required = true): Fieldset
    {
        $boxes = [];

        foreach (DefinitionForm::LOCALES as $locale) {
            $path = $field === '' ? $locale : "{$field}.{$locale}";
            $box = $long ? Textarea::make($path)->rows(2)->maxLength(200) : TextInput::make($path)->maxLength(200);
            $boxes[] = $box->label(config("ride.locale_names.{$locale}", $locale))->required($required && in_array($locale, ['en', 'pl'], true));
        }

        return Fieldset::make($label)->schema($boxes)->columns(1);
    }
}
