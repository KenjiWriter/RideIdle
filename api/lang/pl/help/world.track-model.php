<?php

return [
    'entries' => [
        'speed_kmh' => [
            'label' => 'Prędkość toru',
            'help' => 'Średnia prędkość auta wzorcowego na tym torze, ze wszystkimi trudnościami wliczonymi: zakrętami, nawierzchnią, wzniesieniami i ruchem. Okrążenie wzorcowe to długość podzielona przez tę prędkość; z jego czasu wynikają paliwo, wypłata i XP za okrążenie. Prawdziwe auto jedzie szybciej lub wolniej zależnie od dopasowania do toru.',
            'example' => 'Sprint uliczny Złomowo: 124,8 km/h (2600 m w 75 s); Tor Autodrom: 144 km/h; Kamieniołom Kruszyn (żwir): ok. 91 km/h.',
            'range' => 'Od 20 do 300 km/h, najwyżej cztery miejsca po przecinku.',
            'tip' => 'Nie wiesz, ile wpisać? Przycisk „Podpowiedz prędkość” liczy ją z zakrętów, wzniesień i ruchu. Wolniejszy tor to dłuższe okrążenie i więcej paliwa na kilometr; wypłata na godzinę zostaje taka, jaką ustawisz.',
        ],
        'pay_per_hour' => [
            'label' => 'Wypłata na godzinę ($)',
            'help' => 'Ile dolarów płaci godzina jazdy auta wzorcowego na tym torze w suchą pogodę, zanim zmienią to tier auta, miejsce wśród botów i pogoda. Wpisujesz dolary; gra trzyma centy. Wypłata za okrążenie wychodzi z tej liczby i z czasu okrążenia, więc dłuższy tor nie płaci mniej na godzinę.',
            'example' => '744 $ (tor uliczny Złomowo: 15,50 $ za okrążenie 75 s), 600 $ (Sprint po złomowisku), 1192,50 $ (Tor Autodrom).',
            'range' => 'Co najmniej 0,01 $.',
            'tip' => 'Zarobek na godzinę to wypłata na godzinę minus paliwo na godzinę: to porównują kontrole balansu między torami, więc nowa wypłata zmienia, który tor jest najlepszy na danym poziomie. Deszcz, ulewa i śnieg płacą o 7% mniej (dziś).',
        ],
        'xp_per_hour' => [
            'label' => 'XP na godzinę',
            'help' => 'Ile doświadczenia daje godzina jazdy na tym torze w suchą pogodę: podnosi poziom kierowcy, który otwiera nowe miasta, tory i części. XP za okrążenie wychodzi z tej liczby i z czasu okrążenia, zaokrąglone do całych. Szybsze auto dostaje proporcjonalnie mniej za okrążenie, więc w godzinę każde auto daje tyle samo XP.',
            'example' => '960 XP (tor uliczny Złomowo, 20 XP za okrążenie), 900 XP (Tor Autodrom i sprinty).',
            'range' => 'Od 0 do 3600, najwyżej dwa miejsca po przecinku.',
            'tip' => 'Na bardzo krótkim torze zaokrąglenie XP za okrążenie do całych zmienia wynik nawet o jedną piątą; w mokrej pogodzie jest o 7% mniej.',
        ],
    ],
];
