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

    // Version of the set of rules (prices, rewards, anchors) that runs are frozen with.
    'ruleset_version' => env('RIDE_RULESET_VERSION', 'dev'),

    /*
    |--------------------------------------------------------------------------
    | Konta: linki w e-mailach i powrót z logowania przez dostawcę
    |--------------------------------------------------------------------------
    |
    | Linki z e-maili i przekierowania po logowaniu prowadzą do aplikacji web,
    | więc API musi znać jej adres (WEB_URL, bez ukośnika na końcu).
    |
    */

    'web_url' => rtrim((string) env('WEB_URL', 'http://localhost:5173'), '/'),

    'account' => [
        // How long the link of an e-mail works, in seconds. It works once.
        'link_ttl_seconds' => 1800,
    ],

];
