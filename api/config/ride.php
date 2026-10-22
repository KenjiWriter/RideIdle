<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Obsługiwane języki
    |--------------------------------------------------------------------------
    |
    | Kody publiczne (API, panel, paczki treści) są w formacie BCP 47: pl, en,
    | fr, de, pt-BR. Laravel używa wewnętrznie pt_BR, więc zamiana kodu
    | odbywa się w App\Support\Locale. Brazylijski portugalski to pt-BR,
    | a skrót "br" jest aliasem, tak jak "pt".
    |
    */

    'locales' => ['pl', 'en', 'fr', 'de', 'pt-BR'],

    'default_locale' => 'en',

    // Języki, w których treść z paczek musi być uzupełniona, żeby paczka przeszła walidację.
    'required_content_locales' => ['en', 'pl'],

    // Klucze zapisujemy małymi literami.
    'locale_aliases' => [
        'br' => 'pt-BR',
        'pt' => 'pt-BR',
        'pt-br' => 'pt-BR',
        'en-us' => 'en',
        'en-gb' => 'en',
    ],

    // Nazwy języków w ich własnym języku, do wyboru w interfejsie.
    'locale_names' => [
        'pl' => 'Polski',
        'en' => 'English',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'pt-BR' => 'Português (Brasil)',
    ],

    // Kolejność szukania tłumaczenia treści, gdy brakuje wybranego języka.
    'content_fallbacks' => ['en', 'pl'],

    /*
    |--------------------------------------------------------------------------
    | Konta gościa
    |--------------------------------------------------------------------------
    |
    | Gość bez aktywności przez tyle dni jest usuwany (konto gościa nie ma
    | danych osobowych ani sposobu kontaktu).
    |
    */

    'guest_expiry_days' => 90,

    /*
    |--------------------------------------------------------------------------
    | Paczki treści
    |--------------------------------------------------------------------------
    |
    | Katalog content/ w repozytorium: schema/ ze schematami JSON i packs/ z
    | paczkami (auta, części). Walidacja: php artisan content:validate.
    |
    */

    'content_path' => env('CONTENT_PATH', dirname(base_path()).DIRECTORY_SEPARATOR.'content'),

];
