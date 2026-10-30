<?php

return [
    'entries' => [
        '/tracks/*/speed_kmh' => [
            'label' => 'Prędkość toru (km/h)',
            'help' => 'Średnia prędkość auta wzorcowego na tym torze, ze wszystkimi trudnościami wliczonymi: zakrętami, nawierzchnią, wzniesieniami i ruchem. Okrążenie wzorcowe to długość toru podzielona przez tę prędkość, a paliwo, wypłata i XP za okrążenie wynikają z czasu tego okrążenia.',
            'example' => 'Sprint uliczny Złomowo: 124,8 km/h (2600 m w 75 s), Tor Autodrom: 144 km/h (3200 m w 80 s), Kamieniołom Kruszyn: 90,947 km/h (2400 m w 95 s).',
            'range' => 'Od 20 do 300 km/h, najwyżej cztery miejsca po przecinku (ze schematu).',
            'tip' => 'Im wolniejszy tor (żwir, kręty, górski), tym dłuższe okrążenie i więcej paliwa na kilometr, bo silnik pracuje dłużej. Wypłata na godzinę zostaje taka, jaką ustawisz, więc zmiana prędkości nie zmienia zarobku na godzinę, tylko to, ile kilometrów auto robi w godzinę (a więc zużycie części i serwis). Prawdziwe auto jedzie tor szybciej lub wolniej zależnie od dopasowania.',
        ],
        '/tracks/*/pay_cents_per_hour' => [
            'label' => 'Wypłata na godzinę ($)',
            'help' => 'Ile dolarów płaci godzina jazdy auta wzorcowego na tym torze w suchą pogodę, zanim zmienią to tier auta, miejsce wśród botów i pogoda. Wypłata za okrążenie to ta liczba razy czas okrążenia wzorcowego, więc dłuższy tor nie płaci mniej na godzinę.',
            'example' => 'Sprint uliczny Złomowo: 744 $ na godzinę (15,50 $ za okrążenie 75 s), Sprint po złomowisku: 600 $, Tor Autodrom: 1192,50 $.',
            'range' => 'Co najmniej 0,01 $ (ze schematu).',
            'tip' => 'To główna dźwignia ekonomii toru: zarobek na godzinę to wypłata na godzinę minus paliwo na godzinę. Kotwice ekonomii podają dochód netto godziny dla poziomu toru, a tu wpisujesz brutto.',
        ],
        '/tracks/*/xp_per_hour' => [
            'label' => 'XP na godzinę',
            'help' => 'Ile XP gracza daje godzina jazdy na tym torze w suchą pogodę. XP za okrążenie to ta liczba razy czas okrążenia wzorcowego, zaokrąglone do całych. Szybsze auto dostaje proporcjonalnie mniej za okrążenie, więc w godzinę każde auto daje tyle samo.',
            'example' => 'Sprint uliczny Złomowo: 960 XP na godzinę (20 XP za okrążenie), Tor Autodrom: 900.',
            'range' => 'Od 0 do 3600, najwyżej dwa miejsca po przecinku (ze schematu).',
            'tip' => 'Na bardzo krótkim torze zaokrąglenie do całych XP za okrążenie zmienia wynik o kilkanaście procent: walidator ostrzega, gdy zaokrąglenie zabiera ponad jedną dziesiątą.',
        ],
        '/track_model/fuel_ml_per_s/sprint' => [
            'label' => 'Paliwo na sekundę okrążenia: sprint',
            'help' => 'Ile paliwa spala w każdej sekundzie okrążenia silnik auta o zwykłym zużyciu na torze rodzaju „sprint”. Paliwo okrążenia auta wzorcowego to ta liczba razy czas okrążenia, a łapczywsze auto spala więcej.',
            'example' => 'Sprint 12 ml (0,012 l) na sekundę: Sprint po złomowisku (12 s) spala 144 ml.',
            'range' => 'Od 1 do 1000 ml na sekundę (ze schematu).',
            'tip' => 'Zmiana tej liczby rusza paliwo wszystkich torów tego rodzaju naraz. Zbiornik starcza na pojemność ÷ tę liczbę sekund: 45 l przy 12 ml to ok. godzina, przy 32 ml ok. 23 minuty.',
        ],
        '/track_model/fuel_ml_per_s/standard' => [
            'label' => 'Paliwo na sekundę okrążenia: standard',
            'help' => 'Ile paliwa spala w każdej sekundzie okrążenia silnik auta o zwykłym zużyciu na torze rodzaju „standard”. Paliwo okrążenia auta wzorcowego to ta liczba razy czas okrążenia, a łapczywsze auto spala więcej.',
            'example' => 'Standard 20 ml na sekundę: ulica Złomowo (75 s) spala 1,5 l na okrążenie.',
            'range' => 'Od 1 do 1000 ml na sekundę (ze schematu).',
            'tip' => 'Zmiana tej liczby rusza paliwo wszystkich torów tego rodzaju naraz. Zbiornik starcza na pojemność ÷ tę liczbę sekund: 45 l przy 12 ml to ok. godzina, przy 32 ml ok. 23 minuty.',
        ],
        '/track_model/fuel_ml_per_s/endurance' => [
            'label' => 'Paliwo na sekundę okrążenia: dystans',
            'help' => 'Ile paliwa spala w każdej sekundzie okrążenia silnik auta o zwykłym zużyciu na torze rodzaju „dystans”. Paliwo okrążenia auta wzorcowego to ta liczba razy czas okrążenia, a łapczywsze auto spala więcej.',
            'example' => 'Dystans 32 ml na sekundę: Obwodnica Złomowo (300 s) spala 9,6 l na okrążenie.',
            'range' => 'Od 1 do 1000 ml na sekundę (ze schematu).',
            'tip' => 'Zmiana tej liczby rusza paliwo wszystkich torów tego rodzaju naraz. Zbiornik starcza na pojemność ÷ tę liczbę sekund: 45 l przy 12 ml to ok. godzina, przy 32 ml ok. 23 minuty.',
        ],
    ],
];
