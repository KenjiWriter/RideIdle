<?php

declare(strict_types=1);

use App\Support\Locale;
use Illuminate\Support\Arr;

/** @return array<string, string> */
function gameStrings(string $laravelLocale): array
{
    return Arr::dot(require lang_path("{$laravelLocale}/game.php"));
}

it('has a game.php file for every supported language', function (string $locale) {
    expect(lang_path(Locale::toLaravel($locale).'/game.php'))->toBeFile();
})->with(['pl', 'en', 'fr', 'de', 'pt-BR']);

it('has the same keys as English in every language', function (string $locale) {
    $english = gameStrings('en');
    $translated = gameStrings(Locale::toLaravel($locale));

    expect(array_keys($translated))->toEqualCanonicalizing(array_keys($english));
})->with(['pl', 'en', 'fr', 'de', 'pt-BR']);

it('has no empty strings and keeps the same placeholders', function (string $locale) {
    $english = gameStrings('en');
    $translated = gameStrings(Locale::toLaravel($locale));

    foreach ($english as $key => $text) {
        expect(trim($translated[$key]))->not->toBe('', "{$locale}: {$key} is empty");

        preg_match_all('/:[a-z_]+/', $text, $expected);
        preg_match_all('/:[a-z_]+/', $translated[$key], $actual);

        expect($actual[0])->toEqualCanonicalizing($expected[0], "{$locale}: placeholders differ in {$key}");
    }
})->with(['pl', 'en', 'fr', 'de', 'pt-BR']);

it('ships framework translations for every supported language', function (string $locale) {
    expect(lang_path(Locale::toLaravel($locale).'/validation.php'))->toBeFile();
})->with(['pl', 'en', 'fr', 'de', 'pt-BR']);
