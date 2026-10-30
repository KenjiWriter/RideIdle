<?php

return [
    'entries' => [
        'slug' => [
            'label' => 'Slug (identyfikator)',
            'help' => 'Stały identyfikator auta lub części: małe litery i cyfry połączone myślnikami. Wybierasz go raz, przy tworzeniu; później nie da się go zmienić i nie wróci nawet po zarchiwizowaniu definicji.',
            'example' => 'rdzak-gt-4, turbo-osemka-t2.',
            'range' => 'Do 64 znaków: a–z, 0–9 i pojedyncze myślniki między słowami.',
            'tip' => 'Po slugu odwołują się do auta lub części paczka, zasady (lista sklepu, opony fabryczne), samouczek i auta graczy. Dlatego nazwę wyświetlaną można zmieniać, a slug nie.',
        ],
        'name' => [
            'label' => 'Nazwa',
            'help' => 'Nazwa widoczna dla graczy, osobno w każdym języku gry. Angielski i polski są wymagane; język bez tekstu pokazuje wersję angielską.',
            'example' => 'Rdzak GT-4; Opony uliczne (pl) i Street tires (en).',
            'range' => 'Do 200 znaków w każdym języku.',
        ],
        'description' => [
            'label' => 'Opis',
            'help' => 'Krótki tekst dla gracza, dziś pokazywany na ekranie wyboru wraku na start. Nie jest wymagany; auto bez opisu po prostu go nie ma.',
            'range' => 'Do 200 znaków w każdym języku.',
        ],
        'image_upload' => [
            'label' => 'Wgraj obrazek',
            'help' => 'Obrazek auta lub części, pokazywany na jego kartach tam, gdzie gra nie ma własnego. Bez obrazka definicja wygląda jak dotąd.',
            'range' => 'PNG, WebP lub JPEG, plik do 1 MB, bok od :min do :max pikseli.',
            'tip' => 'Plik jest zapisywany w bazie pod sumą kontrolną swoich bajtów, a definicja tylko wskazuje jego nazwę. Trafia do gry z następną paczką, a paczka zapisana jako plik zabiera go ze sobą.',
        ],
        'image_remove' => [
            'label' => 'Zdejmij obrazek',
            'help' => 'Włącz i zapisz szkic, żeby auto lub część nie miała już obrazka. Pole widać tylko wtedy, gdy obrazek jest ustawiony.',
        ],
    ],
];
