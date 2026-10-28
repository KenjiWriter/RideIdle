<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Content\Definitions\DefinitionForm;
use App\Content\Vocabulary\WebMessages;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * The messages of the app that are shown as a form: for each, a box in every language of the game, with the text of the app in it as the hint of an empty box. A box
 * left empty is a language where the pack says nothing of its own, and a message with all its boxes empty is the text of the app.
 */
final class VocabularyFields
{
    /**
     * @param  list<string>  $keys  the messages to show, in order
     * @param  array<string, mixed>  $document  the document being worked on
     * @return list<mixed>
     */
    public static function schema(array $keys, WebMessages $catalog, array $document): array
    {
        $changed = (array) ($document['messages'] ?? []);
        $language = (string) config('ride.admin_locale');
        $sections = [];

        foreach ($keys as $position => $key) {
            $isChanged = isset($changed[$key]);

            $sections[] = Section::make($isChanged ? "{$key} ●" : $key)
                ->description($catalog->text($key, $language) === null ? __('admin.vocabulary.not_in_app') : __('admin.vocabulary.app_says', ['text' => $catalog->text($key, $language)]))
                ->compact()
                ->collapsible()
                ->collapsed(! $isChanged && count($keys) > 8)
                ->columns(['default' => 1, 'md' => 2, 'xl' => 5])
                ->schema(array_map(
                    static fn (string $locale): TextInput => TextInput::make("values.m{$position}.{$locale}")
                        ->label($locale)
                        ->placeholder($catalog->text($key, $locale))
                        ->maxLength(400),
                    DefinitionForm::LOCALES,
                ));
        }

        return $sections;
    }
}
