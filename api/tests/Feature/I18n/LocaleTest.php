<?php

declare(strict_types=1);

use App\Support\Locale;

it('normalizes language tags to supported locales', function (?string $input, ?string $expected) {
    expect(Locale::normalize($input))->toBe($expected);
})->with([
    ['pl', 'pl'],
    ['EN', 'en'],
    ['en-GB', 'en'],
    ['fr-CH', 'fr'],
    ['de_AT', 'de'],
    ['PT_br', 'pt-BR'],
    ['pt-BR', 'pt-BR'],
    ['pt', 'pt-BR'],
    ['br', 'pt-BR'],
    ['es', null],
    ['*', null],
    ['', null],
    [null, null],
]);

it('picks the best supported language from Accept-Language using q weights', function (?string $header, ?string $expected) {
    expect(Locale::fromAcceptLanguage($header))->toBe($expected);
})->with([
    ['fr-FR,fr;q=0.9,en;q=0.8', 'fr'],
    ['es, de;q=0.7, fr;q=0.9', 'fr'],
    ['pt-BR,pt;q=0.9,en;q=0.8', 'pt-BR'],
    ['en;q=0, de', 'de'],
    ['*', null],
    ['xx, yy;q=0.5', null],
    ['', null],
    [null, null],
]);

it('converts public codes to Laravel locale names', function () {
    expect(Locale::toLaravel('pt-BR'))->toBe('pt_BR')
        ->and(Locale::toLaravel('pl'))->toBe('pl');
});

it('supports exactly the five product languages', function () {
    expect(Locale::supported())->toBe(['pl', 'en', 'fr', 'de', 'pt-BR'])
        ->and(Locale::default())->toBe('en')
        ->and(array_keys(Locale::names()))->toEqualCanonicalizing(Locale::supported());
});
