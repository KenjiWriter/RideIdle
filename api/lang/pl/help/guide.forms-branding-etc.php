<?php

return [
    'entries' => [
        'branding' => [
            'label' => 'Jak działa formularz marki i strony',
            'help' => 'Tu ustawiasz wygląd aplikacji tej gry: kolory, kroje pisma, własny arkusz stylów, bloki strony startowej i podgląd linku. Puste pole to wygląd aplikacji; zapisany szkic trafia do gry z następną opublikowaną paczką.',
            'steps' => [
                'Zacznij od kolorów: zmień kilka (tło, akcent, tekst), resztę zostaw pustą. Kolory mają format #rrggbb.',
                'Wybierz kroje pisma dla nagłówków, tekstu i liczb albo zostaw krój aplikacji.',
                'Dodaj bloki strony startowej od góry (Góra strony, Karty, Tekst, Obrazek, Przyciski) i wpisz teksty co najmniej po angielsku i po polsku.',
                'Ustaw tytuł, opis i obrazek podglądu linku: to widać, gdy ktoś wklei adres gry w czacie.',
                'Własny CSS dodawaj na końcu i tylko gdy kolory nie wystarczą; reguły, które nie przejdą, zgłasza komunikat przy zapisie.',
                'Zapisz szkic i opublikuj paczkę, żeby gracze zobaczyli zmianę.',
            ],
            'example' => 'Gra o statkach: Akcent #2fb7c9 zamiast bursztynu, krój nagłówków „Systemowy szeryfowy”, blok Góra strony z tytułem „Floty na morzu” (po angielsku „Fleets at sea”) i podgląd linku z tym samym tytułem.',
        ],
        'vocabulary' => [
            'label' => 'Jak działa Słownik',
            'help' => 'Słownik zmienia słowa aplikacji dla tej gry (nazwy slotów, menu, teksty ekranów) w pięciu językach. Puste pole to tekst aplikacji; zapisany szkic trafia do gry z następną paczką.',
            'steps' => [
                'Wybierz obszar albo wpisz w polu Szukaj słowo i zatwierdź Enterem; naraz widać najwyżej 30 komunikatów.',
                'Pod kluczem komunikatu jest tekst aplikacji, a w pustym polu języka widać go jako podpowiedź.',
                'Wpisz nowy tekst w językach, które masz; nazwy w klamrach, np. {name}, zostaw bez zmian.',
                'Uzupełnij wszystkie języki, na których ci zależy: pusty język pokaże angielski tekst paczki, a nie tekst aplikacji w tym języku.',
                'Zapisz przed zmianą obszaru, wyszukiwania albo filtra: niezapisane zmiany znikną. Zmienione komunikaty mają kropkę ● przy kluczu.',
                'Cały motyw możesz pobrać do pliku albo wczytać z pliku przyciskami u góry strony; wczytany zastępuje wszystkie słowa paczki w szkicu.',
            ],
            'example' => 'Gra o statkach: slot o kluczu hull dostaje komunikat slot.hull, po angielsku „Hull”, po polsku „Kadłub”.',
        ],
        'rules_text' => [
            'label' => 'Jak działa edycja zasad jako tekstu',
            'help' => 'Zaawansowany sposób: cały dokument zasad albo samouczka jako JSON, dla tego, czego nie pokazują inne strony. Dokument jest sprawdzany tak samo jak na innych stronach i zapisywany jako szkic, który trafia do gry z następną paczką.',
            'steps' => [
                'Wybierz dokument: Zasady gry albo Samouczek.',
                'Zmieniaj tylko to, co trzeba, i zachowaj nawiasy, przecinki i cudzysłowy. Większość liczb wygodniej zmieniać w Studio parametrów.',
                'Zapisz. Błąd składni pokazuje pole, błąd treści czerwone powiadomienie z miejscem i powodem; w obu przypadkach nic się nie zapisuje.',
                'Usunięcie miasta, toru albo kroku samouczka, z którego korzystają gracze, jest odrzucane.',
                'Po zmianie dokumentu w polu Dokument niezapisany tekst znika.',
            ],
            'example' => 'W Zasadach gry zmień w "fuel" liczbę "price_cents_per_liter" z 200 na 250 (to centy: 2,50 $ za litr), zapisz i sprawdź w Studio parametrów, że sekcja Paliwo pokazuje 2,50 $.',
        ],
        'studio' => [
            'label' => 'Jak działa Studio parametrów',
            'help' => 'Studio zmienia liczby gry, jedna sekcja naraz: ceny, czasy, nagrody, zużycie. Liczby są w jednostce, w jakiej się myśli (%, s, $, l); zapisany szkic trafia do gry z następną paczką.',
            'steps' => [
                'Zostaw włączony tryb prosty (tempo i pieniądze gry); wyłącz go, gdy potrzebujesz każdej liczby.',
                'Wybierz sekcję; zdanie pod nią mówi, co zmiana jej liczb zmieni w grze.',
                'Ustaw liczbę suwakiem albo wyłącz Suwaki i wpisz dokładną wartość. Przy liczbie widać jej wartość i, na żółto, wartość żywej paczki, gdy jest inna; strzałka przywraca żywą.',
                'Zapisz przed zmianą sekcji, trybu albo suwaków: niezapisane zmiany znikną.',
                'Użyj Podglądu balansu, żeby zobaczyć, jak zapisany szkic zmienia tempo gry względem żywej paczki.',
            ],
            'example' => 'W sekcji Paliwo litr kosztuje 2,00 $ (w dokumencie 200 centów). Ustaw 2,50 $, zapisz i sprawdź w Podglądzie balansu, jak zmieni się tempo gry.',
        ],
        'model' => [
            'label' => 'Jak działa Model gry',
            'help' => 'Model mówi, z czego składa się auto: sloty, w które idą części (z rolą, którą znają wzory), oraz statystyki, które pokazują ekrany. Zapisany szkic trafia do gry z następną paczką.',
            'steps' => [
                'Dodaj slot: nadaj klucz (małe litery, cyfry, _) i wybierz rolę albo zostaw „Bez roli”.',
                'Ustaw kolejność slotów przeciąganiem: w tej kolejności wypisują je garaż i sklep.',
                'Ukryj slot, jeśli garaż nie ma go wymieniać.',
                'Ułóż statystyki w kolejności ekranów albo pomiń te, których gra ma nie pokazywać.',
                'Wpisz nazwę nowego slotu w Słowniku jako slot.klucz (angielski i polski), żeby gra nie pokazała samego klucza.',
                'Zapisz; odmowa mówi, co jeszcze nazywa slot, którego chcesz się pozbyć.',
            ],
            'example' => 'Gra o statkach: slot hull z rolą Opony (to on rządzi przyczepnością), reactor z rolą Silnik i thrusters z rolą Doładowanie; nazwy „Kadłub”, „Reaktor” i „Silniki manewrowe” wpisane w Słowniku jako slot.hull, slot.reactor i slot.thrusters.',
        ],
    ],
];
