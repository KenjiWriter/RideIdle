<?php

return [
    'entries' => [
        'document' => [
            'label' => 'Dokument',
            'help' => 'Który dokument pokazać jako tekst. Zmiana dokumentu odrzuca to, co wpisano i nie zapisano.',
            'options' => [
                'starter' => ['label' => 'Zasady gry', 'help' => 'Wszystkie liczby i reguły świata (starter.json): tory, ceny, czasy, zużycie, rynek. To samo, co Studio parametrów, ale w całości jako tekst.'],
                'tutorial' => ['label' => 'Samouczek', 'help' => 'Kroki samouczka i ich nagrody (tutorial.json); te same dane, które edytuje strona Samouczek.'],
            ],
        ],
        'json' => [
            'label' => 'Tekst dokumentu (JSON)',
            'help' => 'Cały dokument jako JSON, dla tego, czego nie pokazują inne strony. Sprawdza go ten sam schemat i te same reguły świata co na innych stronach; zapis tworzy szkic, który trafia do gry z następną paczką.',
            'tip' => 'Błąd składni (np. brakujący przecinek) pokazuje samo pole i nic się nie zapisuje; błąd treści (zła wartość, brakujące pole) pokazuje czerwone powiadomienie z miejscem i powodem (do ośmiu pierwszych), również bez zapisu. Dokument równy opublikowanemu nie zostawia szkicu.',
        ],
    ],
];
