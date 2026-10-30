<?php

return [
    'entries' => [
        'vehicle' => [
            'label' => 'Jak działa formularz auta',
            'help' => 'Tu robisz nowe auto albo zmieniasz istniejące. Zapis daje szkic: jest sprawdzany ze schematem i dopiero wtedy zapisany, ale do graczy trafia dopiero z następną paczką, którą opublikujesz.',
            'example' => 'Rdzak GT-4: hatchback, napęd przedni, spalinowy, R4 1,8 l ze strojeniem 1,0, 97 kW, 1000 kg, 175 km/h, prześwit 20, ładunek 120 kg, 4 miejsca, bak 45 l, tier 2, poziom 3, cena 21 500 $, źródła: wrak na start i giełda.',
            'steps' => [
                'Wpisz slug (stały identyfikator, wybierany raz) i nazwę; angielski i polski są wymagane.',
                'Wybierz klasę, napęd i układ napędowy; dla spalinowego podaj silnik fabryczny (układ, pojemność, strojenie) i zbiornik.',
                'Wpisz moc, masę, prędkość maksymalną, prześwit, ładunek i miejsca; najmocniej jazdę zmieniają moc i masa.',
                'Wybierz tier: poziom gracza musi wynosić 3 × tier − 3 (co najmniej 1). Ustaw cenę w dolarach i zaznacz źródła: wrak na start, giełda, dealer.',
                'Zapisz szkic; błędy ze schematu pokaże powiadomienie. Potem opublikuj paczkę: przechodzi bramki symulatora, które pilnują balansu.',
            ],
        ],
        'part' => [
            'label' => 'Jak działa formularz części',
            'help' => 'Tu robisz nową część albo zmieniasz istniejącą. Zapis daje szkic sprawdzany ze schematem, który trafia do gry z następną opublikowaną paczką; do sklepu część wchodzi dopiero, gdy jest na liście sklepu w zasadach (Zasady jako tekst, shop.parts).',
            'example' => 'Opony uliczne T2: slot Opony, rodzaj uliczne, tier 2, cena 570 $, przyczepność 100, prowadzenie 98, hamowanie 100. Turbo Ósemka T2: slot Doładowanie, tier 2, cena 4200 $, moc 1,25, opóźnienie 3, spalanie 1,15, obciążenie 8, co najmniej 4 cylindry.',
            'steps' => [
                'Wpisz slug i nazwę w językach; angielski i polski są wymagane.',
                'Wybierz slot: jego rola mówi, co część robi. Dla opon wybierz rodzaj, dla silnika układ i pojemność.',
                'Wybierz tier i podaj cenę w dolarach; ceny większości części są sprawdzane z krzywą ekonomii (±20%).',
                'Dodaj statystyki jako linie „nazwa i liczba” (np. przyczepność 100, moc 1,25); mnożniki mają 1 jako „bez zmian”.',
                'W razie potrzeby rozwiń „Czego wymaga i do czego pasuje”: minimum cylindrów, doładowanie, układ napędowy, klasy.',
                'Zapisz szkic i opublikuj paczkę; skutek części policzysz wcześniej na stronie Podgląd części.',
            ],
        ],
    ],
];
