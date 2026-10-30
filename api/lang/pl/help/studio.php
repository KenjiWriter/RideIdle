<?php

return [
    'entries' => [
        'simple' => [
            'label' => 'Tryb prosty',
            'help' => 'Włączony: tylko liczby, które ustawiają tempo i pieniądze gry (ceny, nagrody za okrążenie, czasy warsztatu, poziomy), czyli mała część z około 750. Wyłączony: wszystkie liczby sekcji, także rzadko ruszane.',
            'tip' => 'Tryb prosty jest włączony na starcie. Przełączenie odświeża formularz z zapisanego szkicu, więc zapisz zmiany przed przełączeniem.',
        ],
        'section' => [
            'label' => 'Sekcja',
            'help' => 'Liczby gry są podzielone na sekcje; pokazana jest jedna naraz, z liczbą jej liczb w nawiasie (w trybie prostym tylko tych prostych) i zdaniem o tym, co zmiana jej liczb zmieni w grze. Zapisz zmiany, zanim zmienisz sekcję: niezapisane znikną.',
            'options' => [
                'fuel' => ['label' => 'Paliwo', 'help' => 'Cena litra paliwa; od niej zależy koszt każdego okrążenia.'],
                'repair' => ['label' => 'Naprawa przydrożna', 'help' => 'Cena naprawy po awarii na torze.'],
                'breakdown' => ['label' => 'Awarie', 'help' => 'Szansa awarii na okrążeniu i to, jak bardzo zużycie ją zwiększa.'],
                'tow' => ['label' => 'Holowanie', 'help' => 'Czas holowania po awarii i paliwo zostawione na dojazd.'],
                'car' => ['label' => 'Auto: XP, poziomy, masa', 'help' => 'Tempo zdobywania poziomów przez auta, wpływ masy na prowadzenie i nagrody aut wyższych klas.'],
                'engines' => ['label' => 'Silniki', 'help' => 'Moc, masa i wymagania silników.'],
                'drivetrain' => ['label' => 'Napęd', 'help' => 'Jak napęd przedni, tylny i na cztery koła trzyma się nawierzchni oraz jego limity mocy.'],
                'garage' => ['label' => 'Garaże', 'help' => 'Ile aut gracz trzyma w mieście i za ile zaczyna; ceny garaży i wraków z placu.'],
                'market' => ['label' => 'Rynek: giełda, dealer, magazyny, skrzynki', 'help' => 'Ceny aut używanych, odsprzedaż, szanse w magazynach i odświeżanie giełdy.'],
                'clock' => ['label' => 'Zegar gry', 'help' => 'O ile wolniej jeździ się nocą po torach bez świateł.'],
                'player' => ['label' => 'Gracz: XP i poziomy', 'help' => 'Tempo zdobywania poziomów gracza, od którego otwierają się miasta, tory i części.'],
                'start' => ['label' => 'Start', 'help' => 'Przewaga nowego gracza na pierwszym okrążeniu.'],
                'bots' => ['label' => 'Boty', 'help' => 'Czasy rywali względem toru odniesienia i mnożniki nagrody za miejsce.'],
                'shop' => ['label' => 'Sklep', 'help' => 'Skrzynki i rzadkość części: szanse, siła i ceny.'],
                'affixes' => ['label' => 'Afiksy', 'help' => 'Siła, szanse i efekty afiksów części, także zależnych od nawierzchni.'],
                'wear' => ['label' => 'Zużycie i serwis', 'help' => 'Życie części w kilometrach, koszt naprawy i serwisu.'],
                'premium' => ['label' => 'Waluta premium', 'help' => 'Ceny paczek RideCoin, przyspieszanie za monety, cena prawnika, auto-tankowanie (paczki ładunków), konto VIP i przepustka bez limitu tankowania.'],
                'workshop' => ['label' => 'Warsztat', 'help' => 'Czasy montażu, ulepszeń i łączenia, ceny stanowisk i mechaników, długość kolejki.'],
                'tire_matrix' => ['label' => 'Macierz opon', 'help' => 'Przyczepność każdego rodzaju opon na każdej nawierzchni.'],
                'weather' => ['label' => 'Pogoda', 'help' => 'Jak pogoda wpływa na okrążenie.'],
                'regions' => ['label' => 'Miasta (liczby)', 'help' => 'Położenie miast na mapie i ich wymagania.'],
                'roads' => ['label' => 'Drogi', 'help' => 'Odległości między miastami w kilometrach, a z nimi czas i koszt podróży.'],
                'travel' => ['label' => 'Podróże i przyspieszenie', 'help' => 'Prędkość i paliwo podróży oraz przyspieszona jazda z policją i mandatami.'],
                'tracks' => ['label' => 'Tory: czasy, nagrody, wagi', 'help' => 'Długość, czas okrążenia, paliwo, nagroda i XP toru oraz wagi statystyk toru; wprost zmienia zarobek na godzinę.'],
            ],
        ],
        'sliders' => [
            'label' => 'Suwaki',
            'help' => 'Włączone: liczba, której schemat podaje granice, ma suwak z podpowiedzią wartości; pozostałe zawsze są polami. Wyłączone: wszystkie liczby są polami, w które wpisuje się dokładną wartość.',
            'tip' => 'Suwak ma krok liczby i nie nadaje się do bardzo szerokich zakresów (ponad 2000 kroków), tam jest pole. Przełączenie odświeża formularz z zapisanego szkicu, więc zapisz zmiany przed przełączeniem.',
        ],
    ],
];
