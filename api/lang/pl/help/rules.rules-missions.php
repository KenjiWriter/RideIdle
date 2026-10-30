<?php

return [
    'entries' => [
        '/missions/reset_hour' => [
            'label' => 'Godzina początku dnia misji',
            'help' => 'O której godzinie (w strefie czasowej gry) zaczyna się dzień misji, a z nim nowy zestaw dzienny. Tydzień zaczyna się w poniedziałek o tej godzinie, a miesiąc pierwszego dnia o tej godzinie. O tej porze kończy się też poprzedni okres: to, co gracz ukończył i nie odebrał, serwer odbiera za niego, a reszta przepada.',
            'example' => '4: gracz, który gra wieczorem i po północy, ma jeden dzień misji, a nowy zestaw dostaje o 4:00 rano.',
            'range' => '0 – 23 (godzina).',
        ],
        '/missions/periods/daily/bonus_coins' => [
            'label' => 'Premia za komplet: dzień (monety)',
            'help' => 'Ile monet premium (RideCoin) dostaje gracz za wykonanie wszystkich misji dziennego zestawu. To jedyne darmowe monety, jakie dają misje; nagrody za pojedyncze misje nie zawierają monet. Ta liczba rządzi więc całym darmowym dopływem monet z tego okresu, a schemat nie pozwala ustawić więcej niż 5.',
            'example' => '2 monet: trzy okresy zaliczane za każdym razem dają razem 2, 8 i 25 monet, czyli ok. 119 w miesiącu; VIP kosztuje 150 monet, a paczka 50 monet 4,99 zł.',
            'range' => '0 – 5 monet.',
            'tip' => 'Obniżenie premii gracze odczują jak utratę nagrody, więc zaczynaj nisko: podniesienie jej później jest proste.',
        ],
        '/missions/periods/weekly/bonus_coins' => [
            'label' => 'Premia za komplet: tydzień (monety)',
            'help' => 'Ile monet premium (RideCoin) dostaje gracz za wykonanie wszystkich misji tygodniowego zestawu. To jedyne darmowe monety, jakie dają misje; nagrody za pojedyncze misje nie zawierają monet. Ta liczba rządzi więc całym darmowym dopływem monet z tego okresu, a schemat nie pozwala ustawić więcej niż 20.',
            'example' => '8 monet: trzy okresy zaliczane za każdym razem dają razem 2, 8 i 25 monet, czyli ok. 119 w miesiącu; VIP kosztuje 150 monet, a paczka 50 monet 4,99 zł.',
            'range' => '0 – 20 monet.',
            'tip' => 'Obniżenie premii gracze odczują jak utratę nagrody, więc zaczynaj nisko: podniesienie jej później jest proste.',
        ],
        '/missions/periods/monthly/bonus_coins' => [
            'label' => 'Premia za komplet: miesiąc (monety)',
            'help' => 'Ile monet premium (RideCoin) dostaje gracz za wykonanie wszystkich misji miesięcznego zestawu. To jedyne darmowe monety, jakie dają misje; nagrody za pojedyncze misje nie zawierają monet. Ta liczba rządzi więc całym darmowym dopływem monet z tego okresu, a schemat nie pozwala ustawić więcej niż 60.',
            'example' => '25 monet: trzy okresy zaliczane za każdym razem dają razem 2, 8 i 25 monet, czyli ok. 119 w miesiącu; VIP kosztuje 150 monet, a paczka 50 monet 4,99 zł.',
            'range' => '0 – 60 monet.',
            'tip' => 'Obniżenie premii gracze odczują jak utratę nagrody, więc zaczynaj nisko: podniesienie jej później jest proste.',
        ],
        '/missions/periods/daily/cash_ppm' => [
            'label' => 'Gotówka za zestaw: dzień (% baku)',
            'help' => 'Ile gotówki płacą razem wszystkie misje dziennego zestawu, jako część tego, co najlepsze auto gracza zarabia na jednym pełnym baku po odliczeniu paliwa (100% to cały bak). Zestaw dzieli kwotę między swoje misje według ich udziałów, więc płaci tyle samo, jakie misje wylosuje. To dodatek do zarobku aut i nie liczy się w ekonomii gry: bramki i symulator go nie widzą.',
            'example' => '6%: gracz, którego bak zarabia 1 000 $, dostaje za cały zestaw razem ok. 60 $.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/weekly/cash_ppm' => [
            'label' => 'Gotówka za zestaw: tydzień (% baku)',
            'help' => 'Ile gotówki płacą razem wszystkie misje tygodniowego zestawu, jako część tego, co najlepsze auto gracza zarabia na jednym pełnym baku po odliczeniu paliwa (100% to cały bak). Zestaw dzieli kwotę między swoje misje według ich udziałów, więc płaci tyle samo, jakie misje wylosuje. To dodatek do zarobku aut i nie liczy się w ekonomii gry: bramki i symulator go nie widzą.',
            'example' => '20%: gracz, którego bak zarabia 1 000 $, dostaje za cały zestaw razem ok. 200 $.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/monthly/cash_ppm' => [
            'label' => 'Gotówka za zestaw: miesiąc (% baku)',
            'help' => 'Ile gotówki płacą razem wszystkie misje miesięcznego zestawu, jako część tego, co najlepsze auto gracza zarabia na jednym pełnym baku po odliczeniu paliwa (100% to cały bak). Zestaw dzieli kwotę między swoje misje według ich udziałów, więc płaci tyle samo, jakie misje wylosuje. To dodatek do zarobku aut i nie liczy się w ekonomii gry: bramki i symulator go nie widzą.',
            'example' => '60%: gracz, którego bak zarabia 1 000 $, dostaje za cały zestaw razem ok. 600 $.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/daily/xp_ppm' => [
            'label' => 'XP za zestaw: dzień (% baku)',
            'help' => 'Ile XP gracza (poziomu kierowcy) płacą razem wszystkie misje dziennego zestawu, jako część XP, które najlepsze auto gracza zbiera na jednym pełnym baku. Poziom gracza otwiera tory, miasta i części, a samouczek mówi, że poziomy mają być pracą okrążeń, więc trzymaj tę liczbę małą: każdy punkt przesuwa tempo całej gry.',
            'example' => '5%: gracz, którego bak daje 1 000 XP, dostaje za cały zestaw razem ok. 50 XP.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/weekly/xp_ppm' => [
            'label' => 'XP za zestaw: tydzień (% baku)',
            'help' => 'Ile XP gracza (poziomu kierowcy) płacą razem wszystkie misje tygodniowego zestawu, jako część XP, które najlepsze auto gracza zbiera na jednym pełnym baku. Poziom gracza otwiera tory, miasta i części, a samouczek mówi, że poziomy mają być pracą okrążeń, więc trzymaj tę liczbę małą: każdy punkt przesuwa tempo całej gry.',
            'example' => '15%: gracz, którego bak daje 1 000 XP, dostaje za cały zestaw razem ok. 150 XP.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/monthly/xp_ppm' => [
            'label' => 'XP za zestaw: miesiąc (% baku)',
            'help' => 'Ile XP gracza (poziomu kierowcy) płacą razem wszystkie misje miesięcznego zestawu, jako część XP, które najlepsze auto gracza zbiera na jednym pełnym baku. Poziom gracza otwiera tory, miasta i części, a samouczek mówi, że poziomy mają być pracą okrążeń, więc trzymaj tę liczbę małą: każdy punkt przesuwa tempo całej gry.',
            'example' => '40%: gracz, którego bak daje 1 000 XP, dostaje za cały zestaw razem ok. 400 XP.',
            'range' => '0 – 1000%.',
        ],
    ],
];
