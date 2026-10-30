<?php

declare(strict_types=1);

namespace App\Filament\Resources\Definitions\Forms;

use App\Content\Definitions\DefinitionForm;
use App\Filament\Support\FieldHelp;
use App\Services\ContentImages;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;

/** The parts of the forms of cars and parts that are the same: the slug and the texts in the languages of the game. */
final class DefinitionFields
{
    /** The slug is chosen when a definition is made and never changed afterwards. */
    public static function slug(): TextInput
    {
        return TextInput::make('slug')
            ->helpKey('definition.slug')
            ->required()
            ->maxLength(64)
            ->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')
            ->disabled(fn (?object $record): bool => $record !== null)
            ->dehydrated();
    }

    /**
     * The picture of a car or of a part: the one the draft names, a box for another one, and a way to take it off. The file is not kept by the box but by the page
     * that saves the draft (see ChecksDefinitionDrafts), which puts its name in `image`.
     */
    public static function image(): Section
    {
        return Section::make(__('admin.definitions.sections.image'))->schema([
            Hidden::make('image'),
            View::make('filament.forms.definition-image')->viewData(fn (Get $get): array => ['name' => $get('image')]),
            FileUpload::make('image_upload')
                ->helpKey('definition.image_upload', ['min' => ContentImages::MIN_SIDE, 'max' => ContentImages::MAX_SIDE])
                ->storeFiles(false)
                ->acceptedFileTypes(['image/png', 'image/webp', 'image/jpeg'])
                ->maxSize(intdiv(ContentImages::MAX_BYTES, 1024)),
            Toggle::make('image_remove')->helpKey('definition.image_remove')->visible(fn (Get $get): bool => filled($get('image')))->default(false),
        ]);
    }

    /**
     * A text in every language of the game: English and Polish are required, the others fall back to English, which the validation warns about.
     *
     * @param  string  $field  where the texts are in the state (the languages themselves when it is empty, for a list of texts)
     * @param  string  $help  the key of the help of the text, which the first box says and every box is marked with
     * @param  bool  $long  a box for a paragraph and not a line
     */
    public static function texts(string $field, string $label, string $help, bool $long = false, bool $required = true): Fieldset
    {
        $boxes = [];

        foreach (DefinitionForm::LOCALES as $position => $locale) {
            $path = $field === '' ? $locale : "{$field}.{$locale}";
            $box = $long ? Textarea::make($path)->rows(2)->maxLength(200) : TextInput::make($path)->maxLength(200);
            $box->label(config("ride.locale_names.{$locale}", $locale))->required($required && in_array($locale, ['en', 'pl'], true));
            $boxes[] = FieldHelp::apply($box, $help, label: false, help: $position === 0);
        }

        return Fieldset::make($label)->schema($boxes)->columns(1);
    }
}
