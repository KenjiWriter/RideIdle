<?php

return [
    'entries' => [
        'slots' => [
            'label' => 'Sloty',
            'help' => 'Miejsca w aucie, w które idą części, w kolejności, w jakiej wypisują je garaż i sklep. Od 1 do 24 slotów; przeciągnij wiersz, żeby zmienić kolejność.',
            'range' => 'Od 1 do 24 slotów.',
            'tip' => 'Slotu, który jeszcze nazywa jakaś część albo reguła, nie da się usunąć: zapis zostanie odrzucony z listą miejsc, które go używają. Publikacja nie wyjmie też slotu, w którym gracze mają części, więc zmiana klucza używanego slotu jest zablokowana.',
        ],
        'key' => [
            'label' => 'Klucz slotu',
            'help' => 'Nazwa, którą slot nosi w częściach, regułach i autach graczy. Nazwę widoczną w grze (np. „Opony”) podaje Słownik jako slot.klucz; dla nowego klucza trzeba ją tam wpisać (angielski i polski).',
            'example' => 'tires, suspension, brakes, engine, boost, gearbox, exhaust, cooling, tank, ecu (model gry o samochodach).',
            'range' => 'Do 32 znaków: małe litery, cyfry i _, zaczyna się od litery, bez powtórzeń.',
        ],
        'role' => [
            'label' => 'Rola slotu',
            'help' => 'Mówi wzorom gry, czym ten slot jest w tej grze, bez względu na jego klucz: slot z rolą „Opony” rządzi przyczepnością, nawet jeśli nazywa się „kadłub”. Rola należy do jednego slotu, a gra musi mieć slot z rolą opon. Bez roli slot trzyma części, których statystyki wzory już czytają (jak wydech).',
            'tip' => 'Części w slocie z rolą opon muszą mieć rodzaj opon, a w slocie z rolą silnika silnik (układ i pojemność); w innych slotach części nie mają tych pól.',
            'options' => [
                'tires' => ['label' => 'Opony', 'help' => 'Od nich zaczyna się przyczepność, prowadzenie i hamowanie, a rodzaj opon wybiera kolumnę macierzy nawierzchni. Rola obowiązkowa.'],
                'suspension' => ['label' => 'Zawieszenie', 'help' => 'Prowadzenie i prześwit (terenowość) auta.'],
                'brakes' => ['label' => 'Hamulce', 'help' => 'Hamowanie auta.'],
                'engine' => ['label' => 'Silnik', 'help' => 'Moc i masa auta; część w tym slocie zastępuje silnik fabryczny.'],
                'boost' => ['label' => 'Doładowanie', 'help' => 'Przyrost mocy, opóźnienie i obciążenie silnika.'],
                'gearbox' => ['label' => 'Skrzynia biegów', 'help' => 'Limit prędkości maksymalnej i ruszanie.'],
                'cooling' => ['label' => 'Chłodzenie', 'help' => 'Chłodnica doładowania i radiator: od niej zależy, ile doładowanie traci.'],
                'tank' => ['label' => 'Zbiornik', 'help' => 'Pojemność baku, czyli jak daleko jedzie auto.'],
            ],
        ],
        'hidden' => [
            'label' => 'Ukryty w garażu',
            'help' => 'Ukryty slot nadal może mieć części i ma swoje miejsce w regułach, ale garaż go nie wymienia (w grze o samochodach tak jest ze slotem „ecu”). Ukryty slot nie potrzebuje nazwy w Słowniku.',
        ],
        'stats' => [
            'label' => 'Statystyki',
            'help' => 'Statystyki auta, które pokazują ekrany garażu i warsztatu, w kolejności listy. Pominięta statystyka nie jest pokazywana, a nowej dodać się nie da, bo statystyki liczą wzory.',
            'tip' => 'Lista wpływa tylko na to, co widać, i na ogólny wynik auta (średnia pasków). Żaden wzór wyścigu jej nie czyta, więc pominięcie statystyki nie zmienia jazdy.',
        ],
        'stat' => [
            'label' => 'Statystyka',
            'help' => 'Którą statystykę pokazać w tym miejscu listy. Każda może wystąpić tylko raz.',
            'options' => [
                'accel' => ['label' => 'Przyspieszenie', 'help' => 'Moc auta na tonę: im większa, tym szybciej auto rusza i nabiera prędkości.'],
                'top_speed' => ['label' => 'Prędkość maks.', 'help' => 'Rośnie z mocą (z pierwiastkiem sześciennym zysku mocy) i nie przekracza limitu skrzyni biegów.'],
                'grip' => ['label' => 'Przyczepność', 'help' => 'Bierze się z opon: jak mocno auto trzyma się drogi.'],
                'handling' => ['label' => 'Prowadzenie', 'help' => 'Z opon i zawieszenia, mnożone przez masę: ciężkie auto gorzej skręca.'],
                'braking' => ['label' => 'Hamowanie', 'help' => 'Z opon i hamulców, mnożone przez masę: ciężkie auto gorzej hamuje.'],
                'clearance' => ['label' => 'Terenowość', 'help' => 'Prześwit: zaczyna się od nadwozia i jest uśredniany z zawieszeniem.'],
            ],
        ],
    ],
];
