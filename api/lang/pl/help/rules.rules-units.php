<?php

return [
    'entries' => [
        '/units/lap/metres' => [
            'label' => 'Jednostka okrążenia: ile metrów',
            'help' => 'Ile metrów ma jedna jednostka, w której ta gra liczy długość okrążenia toru: 1 dla metrów, 1852 dla mili morskiej, 1609,344 dla mili lądowej. Liczby w regułach i w bazie zostają w metrach; jednostka mówi, w czym wpisuje się i pokazuje długość toru.',
            'example' => 'Gra samochodowa: 1 (metr). Gra o statkach: 1852 (mila morska).',
            'range' => 'Liczba większa od 0 (ze schematu).',
            'tip' => 'Zmiana nie rusza żadnego czasu ani paliwa: zmienia tylko to, co widzi gracz i co wpisuje operator.',
        ],
        '/units/travel/metres' => [
            'label' => 'Jednostka podróży: ile metrów',
            'help' => 'Ile metrów ma jedna jednostka, w której ta gra liczy drogi między miastami, przebieg auta, interwał serwisu, życie części i przebieg aut na giełdzie: 1000 dla kilometrów, 1852 dla mili morskiej, 1609,344 dla mili lądowej. Liczby w regułach i w bazie zostają w kilometrach; jednostka mówi, w czym je pokazać i wpisać.',
            'example' => 'Gra samochodowa: 1000 (kilometr). Gra o statkach: 1852 (mila morska).',
            'range' => 'Liczba większa od 0 (ze schematu).',
            'tip' => 'Droga w regułach to całe kilometry, więc 20 mil morskich to w dokumencie 37 km (37,04 zaokrąglone), a na ekranie 19,98 mili.',
        ],
        '/units/speed/kmh' => [
            'label' => 'Jednostka prędkości: ile km/h',
            'help' => 'Ile km/h to jedna jednostka prędkości w tej grze: 1 dla km/h, 1,852 dla węzła, 1,609344 dla mili na godzinę. Prędkości w regułach i w paczkach zostają w km/h; jednostka mówi, w czym je pokazać i wpisać.',
            'example' => 'Gra samochodowa: 1 (km/h). Gra o statkach: 1,852 (węzeł).',
            'range' => 'Liczba większa od 0 (ze schematu).',
        ],
        '/units/lap/decimals' => [
            'label' => 'Okrążenie: miejsca po przecinku',
            'help' => 'Ile miejsc po przecinku ma liczba, gdy gracz widzi ją w tej jednostce (0 to liczba całkowita). Dotyczy tylko wyświetlania; pola formularzy mają zawsze tyle miejsc, ile trzeba, żeby wpisana wartość wróciła co do metra.',
            'example' => 'Długość okrążenia w milach morskich: 2 miejsca (1,4 nm); droga w milach morskich: 0 (20 nm).',
            'range' => 'Od 0 do 4 (ze schematu).',
        ],
        '/units/travel/decimals' => [
            'label' => 'Podróż: miejsca po przecinku',
            'help' => 'Ile miejsc po przecinku ma liczba, gdy gracz widzi ją w tej jednostce (0 to liczba całkowita). Dotyczy tylko wyświetlania; pola formularzy mają zawsze tyle miejsc, ile trzeba, żeby wpisana wartość wróciła co do metra.',
            'example' => 'Długość okrążenia w milach morskich: 2 miejsca (1,4 nm); droga w milach morskich: 0 (20 nm).',
            'range' => 'Od 0 do 4 (ze schematu).',
        ],
        '/units/speed/decimals' => [
            'label' => 'Prędkość: miejsca po przecinku',
            'help' => 'Ile miejsc po przecinku ma liczba, gdy gracz widzi ją w tej jednostce (0 to liczba całkowita). Dotyczy tylko wyświetlania; pola formularzy mają zawsze tyle miejsc, ile trzeba, żeby wpisana wartość wróciła co do metra.',
            'example' => 'Długość okrążenia w milach morskich: 2 miejsca (1,4 nm); droga w milach morskich: 0 (20 nm).',
            'range' => 'Od 0 do 4 (ze schematu).',
        ],
        '/units/fuel_economy/per' => [
            'label' => 'Zużycie paliwa na ile jednostek podróży',
            'help' => 'Na ile jednostek podróży gracz widzi zużycie paliwa: 100 daje „l/100 km”, 1 daje „l na milę morską”. Tylko wyświetlanie; liczby zużycia w grze się nie zmieniają.',
            'example' => '100 w grze samochodowej, 1 w grze o statkach.',
            'range' => 'Jedna z liczb: 1, 10, 100 (ze schematu).',
        ],
        '/track_model/advisor/free_speed_kmh/asphalt' => [
            'label' => 'Prędkość swobodna: asfalt (km/h)',
            'help' => 'Od jakiej prędkości kalkulator trudności w formularzu toru zaczyna liczyć dla nawierzchni „asfalt”: prędkość auta wzorcowego na prostym, pustym torze. Gra tej liczby nie czyta: używa jej tylko przycisk „Podpowiedz prędkość”.',
            'example' => 'Asfalt 180 km/h.',
            'range' => 'Od 20 do 300 km/h (ze schematu).',
            'tip' => 'Zmiana nie rusza żadnego istniejącego toru; wpływa tylko na to, co kalkulator zaproponuje następnym razem.',
        ],
        '/track_model/advisor/free_speed_kmh/gravel' => [
            'label' => 'Prędkość swobodna: żwir (km/h)',
            'help' => 'Od jakiej prędkości kalkulator trudności w formularzu toru zaczyna liczyć dla nawierzchni „żwir”: prędkość auta wzorcowego na prostym, pustym torze. Gra tej liczby nie czyta: używa jej tylko przycisk „Podpowiedz prędkość”.',
            'example' => 'Żwir 130 km/h.',
            'range' => 'Od 20 do 300 km/h (ze schematu).',
            'tip' => 'Zmiana nie rusza żadnego istniejącego toru; wpływa tylko na to, co kalkulator zaproponuje następnym razem.',
        ],
        '/track_model/advisor/corner_loss_kmh' => [
            'label' => 'Strata prędkości na zakręt na kilometr (km/h)',
            'help' => 'Ile km/h kalkulator trudności odejmuje za każdy ostry zakręt na kilometr toru. Gra tej liczby nie czyta: używa jej tylko przycisk „Podpowiedz prędkość”.',
            'example' => '10 km/h: tor z 4 zakrętami na kilometr traci 40 km/h.',
            'range' => 'Od 0 do 100 km/h (ze schematu).',
        ],
        '/track_model/advisor/climb_loss_kmh' => [
            'label' => 'Strata prędkości na 10 m przewyższenia na kilometr (km/h)',
            'help' => 'Ile km/h kalkulator trudności odejmuje za każde 10 metrów przewyższenia na kilometr toru. Gra tej liczby nie czyta: używa jej tylko przycisk „Podpowiedz prędkość”.',
            'example' => '5 km/h: tor z przewyższeniem 20 m na kilometr traci 10 km/h.',
            'range' => 'Od 0 do 100 km/h (ze schematu).',
        ],
        '/track_model/advisor/traffic_loss_kmh' => [
            'label' => 'Strata prędkości na stopień ruchu (km/h)',
            'help' => 'Ile km/h kalkulator trudności odejmuje za każdy stopień ruchu i wąskich przejazdów (0 to pusty tor, 3 to ciasno i tłoczno). Gra tej liczby nie czyta: używa jej tylko przycisk „Podpowiedz prędkość”.',
            'example' => '5 km/h: ruch o stopniu 2 odejmuje 10 km/h.',
            'range' => 'Od 0 do 100 km/h (ze schematu).',
        ],
    ],
];
