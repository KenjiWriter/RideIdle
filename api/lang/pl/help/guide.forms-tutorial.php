<?php

return [
    'entries' => [
        'tutorial' => [
            'label' => 'Jak działa samouczek',
            'help' => 'Samouczek prowadzi nowego kierowcę SMS-ami od mentora (K.) przez pierwsze minuty, pierwszy dzień i pierwszy tydzień gry. Zmiany zapisują się najpierw jako szkic i trafiają do gry dopiero z następną opublikowaną paczką.',
            'steps' => [
                'U góry ustaw „Pierwszy przejazd”: paliwo na starcie, okrążenie pierwszej awarii, najdłuższe pierwsze zlecenie, kwotę, którą pokrywa mentor, i (opcjonalnie) ogłoszenie na giełdzie.',
                'Dodaj krok: nadaj mu stały klucz, fazę i numer mentora, a potem wybierz, kiedy się zaczyna i kiedy kończy (fakty o kierowcy, np. lap_completed albo cash:450).',
                'Napisz cel na pasku celu, do dwóch wiadomości mentora i napis przycisku odpowiedzi; opcjonalnie dopisz, na co krok czeka. Krok bez wiadomości jest wykonywany bez pokazywania.',
                'Wskaż, co ekran ma podświetlić, i zlecenia warsztatu, które krok skraca; w razie potrzeby ustaw część na start kroku i nagrodę na koniec.',
                'Nie zmieniaj kluczy i nie usuwaj kroków, w których są gracze: publikacja takiej paczki jest odrzucana.',
                'Zapisz szkic, obejrzyj go na stronie „Zmiany do publikacji” i zleć paczkę na stronie „Publikacje”.',
            ],
            'example' => 'Krok „exchange” (faza 3): początek cash:450 (czeka, aż kierowcę stać na auto), koniec car_bought, podświetla giełdę, a ogłoszenie samouczka (Rdzak GT-2, 25 lat, 280 000 km, stan 30%, $450) czeka w jego mieście; po zakupie mentor oddaje $300.',
        ],
    ],
];
