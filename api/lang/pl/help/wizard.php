<?php

return [
    'entries' => [
        'start' => [
            'label' => 'Treść na start',
            'help' => 'Z czym nowa gra zaczyna. Gra powstaje jako szkic w każdym przypadku; pierwsza paczka jest od razu żywa, gdy jest treść, z której można zacząć.',
            'tip' => 'Jeśli treści nie da się wstawić (kopiowana gra nie ma żywej paczki albo treść nie przechodzi kontroli), gra i tak powstaje, bez treści, a panel o tym mówi; nic nie przepada.',
            'options' => [
                'template' => ['label' => 'Gra samochodowa (szablon)', 'help' => 'Auta, części, tory, reguły, samouczek i teksty gry samochodowej z repozytorium stają się pierwszą, od razu żywą paczką; najlepiej zacząć od czegoś, co już działa, i to zmieniać.'],
                'copy' => ['label' => 'Kopia innej gry', 'help' => 'Paczka, w którą gra teraz wybrana gra, staje się pierwszą paczką nowej (bez jej graczy, kont i niewydanych szkiców).'],
                'none' => ['label' => 'Bez treści', 'help' => 'Pusta gra. Zanim da się ją uruchomić, trzeba jej dać paczkę.'],
            ],
        ],
        'copy_from' => [
            'label' => 'Którą grę skopiować',
            'help' => 'Gra, której żywa paczka staje się pierwszą paczką nowej. Kopiuje się tylko paczka, w którą ta gra gra teraz: nie jej szkice, gracze ani konta.',
            'tip' => 'Gdy wybrana gra nie ma żywej paczki, nowa gra powstaje bez treści i panel o tym ostrzega.',
        ],
        'request_domain' => [
            'label' => 'Ustaw domenę i SSL teraz',
            'help' => 'Po utworzeniu gry zleć serwerowi ustawienie domeny (wpis serwera WWW) i certyfikatu SSL. Serwer podejmuje zlecenie w ciągu minuty, a jego przebieg widać na stronie „Domena i SSL”.',
            'tip' => 'Domena powinna już wskazywać na ten serwer (użyj „Sprawdź DNS” powyżej), a instalacja musi mieć adres e-mail dla Let’s Encrypt (ACME_EMAIL); bez nich przełącznik się nie pokazuje albo zlecenie jest odrzucane, a gra i tak powstaje. Zlecenie można dać później na stronie „Domena i SSL”.',
        ],
    ],
];
