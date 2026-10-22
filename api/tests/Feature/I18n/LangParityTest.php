<?php

declare(strict_types=1);

use App\Support\Locale;
use Illuminate\Support\Arr;

/** Game-specific translation files that every language must provide (framework files come from laravel-lang). */
const GAME_LANG_FILES = ['game', 'account'];

/** @return array<string, string> */
function langStrings(string $laravelLocale, string $file): array
{
    return Arr::dot(require lang_path("{$laravelLocale}/{$file}.php"));
}

it('has every game translation file for every supported language', function (string $locale, string $file) {
    expect(lang_path(Locale::toLaravel($locale)."/{$file}.php"))->toBeFile();
})->with(['pl', 'en', 'fr', 'de', 'pt-BR'])->with(GAME_LANG_FILES);

it('has the same keys as English in every language', function (string $locale, string $file) {
    $english = langStrings('en', $file);
    $translated = langStrings(Locale::toLaravel($locale), $file);

    expect(array_keys($translated))->toEqualCanonicalizing(array_keys($english));
})->with(['pl', 'en', 'fr', 'de', 'pt-BR'])->with(GAME_LANG_FILES);

it('has no empty strings and keeps the same placeholders', function (string $locale, string $file) {
    $english = langStrings('en', $file);
    $translated = langStrings(Locale::toLaravel($locale), $file);

    foreach ($english as $key => $text) {
        expect(trim($translated[$key]))->not->toBe('', "{$locale}: {$file}.{$key} is empty");

        preg_match_all('/:[a-z_]+/', $text, $expected);
        preg_match_all('/:[a-z_]+/', $translated[$key], $actual);

        expect($actual[0])->toEqualCanonicalizing($expected[0], "{$locale}: placeholders differ in {$file}.{$key}");
    }
})->with(['pl', 'en', 'fr', 'de', 'pt-BR'])->with(GAME_LANG_FILES);

it('ships framework translations for every supported language', function (string $locale) {
    expect(lang_path(Locale::toLaravel($locale).'/validation.php'))->toBeFile();
})->with(['pl', 'en', 'fr', 'de', 'pt-BR']);
