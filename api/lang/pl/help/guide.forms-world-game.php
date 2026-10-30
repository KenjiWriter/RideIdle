<?php

return [
    'entries' => [
        'world' => [
            'label' => 'Jak działa formularz świata',
            'help' => 'Tu ustawiasz miejsca, w których auta zarabiają: miasta z ich pogodą, drogi między nimi i tory. To, co wpiszesz, zapisuje się jako szkic reguł i trafia do graczy dopiero z następną opublikowaną paczką.',
            'steps' => [
                'Zakładka Miasta: dodaj miasto ze slugiem, nazwami w językach, miejscem na mapie i szansami pogody (razem 100%).',
                'Zakładka Drogi: połącz miasta drogami i podaj ich długość w km; od pierwszego miasta musi dać się dojechać do każdego.',
                'Zakładka Tory: dodaj tor w mieście: nawierzchnia, rodzaj, poziom, długość okrążenia, okrążenie wzorcowe, paliwo, wypłata i XP.',
                'Ustaw, czego tor wymaga od auta (procenty dające razem 100), i wartość odniesienia każdej z tych statystyk.',
                'Zapisz szkic; zmiany trafią do graczy z następną opublikowaną paczką (strona Publikacje).',
            ],
            'example' => 'Żwirowy sprint w Kruszynie, jak w grze samochodowej: slug kruszyn-gravel-sprint, nawierzchnia Żwir, rodzaj Sprint, poziom 3, 380 m, okrążenie wzorcowe 15 000 ms (15 s), paliwo 180 ml, wypłata $2,92, XP 3; wymaga Przyspieszenia 20, Przyczepności 30, Prowadzenia 15, Hamowania 10, Terenowości 25 (wartości odniesienia 65, 92, 71, 71, 80).',
        ],
        'game' => [
            'label' => 'Jak działa formularz gry',
            'help' => 'To jedna gra instalacji: jej nazwa, adres, język, kolor, ikony i to, czy działa. Sama gra (auta, reguły, teksty) jest na innych stronach; ten formularz to tylko jej tożsamość.',
            'steps' => [
                'Zmień nazwę, język albo kolor; pasek nad formularzem i podgląd pokazują, jak gra będzie wyglądać, zanim zapiszesz.',
                'Dodaj albo zmień domenę, potem otwórz stronę „Domena i SSL”: DNS musi wskazywać na ten serwer, zanim wystawi się certyfikat.',
                'Wgraj kwadratową ikonę (i, jeśli chcesz, ikonę z marginesem dla telefonów, które przycinają ikony).',
                'Ustaw status Działa, gdy treść i domena są gotowe; szkic nie odpowiada nikomu.',
                'Zapisz. Sluga i gry domyślnej nie zmienia się tutaj.',
            ],
            'example' => 'Statki Kosmiczne pod adresem statki.example.com: język polski, kolor #1a2b4c, ikona 512 × 512, status Działa.',
        ],
        'wizard' => [
            'label' => 'Jak kreator tworzy grę',
            'help' => 'Gra powstaje w trzech krokach: nazwa i adres, treść, od której zaczyna, i to, co serwer robi z domeną. Zaczyna jako szkic, który nie odpowiada nikomu, dopóki go nie uruchomisz; slug, kolory, ikony i wygląd ustawisz później.',
            'steps' => [
                'Nazwa i domena: wpisz nazwę (slug podpowie się z niej), domenę, jeśli ją masz, język i kolor.',
                'Punkt startowy: wybierz grę samochodową jako szablon, kopię innej gry albo brak treści.',
                'Domena i SSL: sprawdź, czy domena wskazuje na ten serwer, i jeśli chcesz, zleć serwerowi ustawienie jej od razu.',
                'Naciśnij „Utwórz grę”. Panel przeniesie cię na stronę „Domena i SSL” nowej gry i przełączy się na nią.',
                'Gdy treść i domena są gotowe, ustaw status Działa w edycji gry.',
            ],
            'example' => 'Nazwa „Statki Kosmiczne”, domena statki.example.com, język polski, start od gry samochodowej, zlecenie domeny: gra w szkicu z żywą paczką gry samochodowej i zleceniem czekającym na serwer.',
        ],
    ],
];
