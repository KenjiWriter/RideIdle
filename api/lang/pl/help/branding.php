<?php

return [
    'entries' => [
        'color_bg' => [
            'label' => 'Tło strony',
            'help' => 'Kolor tła całej aplikacji i pól do wpisywania. Zwykle najciemniejszy kolor motywu; samo tło strony to ten kolor nieco przyciemniony.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
            'tip' => 'Od tego koloru liczone są też ciemne wnęki i cienie pod panelami, więc jego zmiana przesuwa cały „dół” motywu.',
        ],
        'color_surface' => [
            'label' => 'Powierzchnia (karty)',
            'help' => 'Kolor kart i paneli: ekranów garażu, sklepu i warsztatu oraz płaskich kart na stronie startowej. Powinien być lekko jaśniejszy od tła.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_surface_2' => [
            'label' => 'Powierzchnia, stopień 2',
            'help' => 'Jaśniejsza powierzchnia: górna część paneli i kafelków, nagłówki tabel oraz rozświetlone tło sceny, na której stoi auto.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_surface_3' => [
            'label' => 'Powierzchnia, stopień 3',
            'help' => 'Najjaśniejsza powierzchnia: płytki przycisków, chipy, górny pasek gry i puste kratki paska paliwa.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_line' => [
            'label' => 'Linia',
            'help' => 'Cienkie linie i ramki: podziały wierszy tabel, obramowania kart i pasków.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_line_2' => [
            'label' => 'Linia mocniejsza',
            'help' => 'Wyraźniejsze ramki: pola do wpisywania, przyciski drugorzędne, znaczniki i krawędzie płytek.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_ink' => [
            'label' => 'Tekst główny',
            'help' => 'Kolor tytułów, liczb i zwykłego tekstu. Musi wyraźnie odcinać się od tła i powierzchni, bo to na nim opiera się czytelność całej aplikacji.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
            'tip' => 'Ten kolor, w połowie przezroczysty, jest też konturem nazwy auta rysowanej za autem na scenie.',
        ],
        'color_muted' => [
            'label' => 'Tekst przygaszony',
            'help' => 'Tekst drugiego planu: opisy, akapity stron, wiersze tabel i ikona zębatki w górnym pasku.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_faint' => [
            'label' => 'Tekst słaby',
            'help' => 'Najcichszy tekst: małe podpisy nad polami, podpowiedzi w pustych polach i puste sloty. Nie obniżaj kontrastu tak, żeby przestał być czytelny.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
            'tip' => 'Kilka drobnych elementów ma kolory na stałe (np. szare podpisy dolnego menu, linia pod tytułem ekranu i telefon samouczka), więc kolory z tej strony ich nie zmienią.',
        ],
        'color_amber' => [
            'label' => 'Akcent (domyślnie bursztyn)',
            'help' => 'Główny kolor akcentu, czyli wszystko, co można nacisnąć albo co jest wyróżnione: główny przycisk, odznaka poziomu, paski postępu, aktywna zakładka menu, ramka wyróżnionej karty, pasy ostrzegawcze i moneta.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
            'tip' => 'Jasne i ciemne odcienie przycisku, jego krawędź i poświata są liczone z tego jednego koloru. „Amber” to tylko nazwa roli: kolor może być dowolny.',
        ],
        'color_amber_ink' => [
            'label' => 'Tekst na akcencie',
            'help' => 'Kolor napisów i ikon leżących na akcencie: tekst głównego przycisku, numer poziomu, etykiety na płytkach. Musi mocno kontrastować z kolorem akcentu.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_amber_bg' => [
            'label' => 'Tło akcentu',
            'help' => 'Bardzo ciemny odcień akcentu: tło wiersza „Ty” w tabeli wyścigu i drugi kolor pasów ostrzegawczych na stronie startowej.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_cyan' => [
            'label' => 'Drugi akcent (domyślnie turkus)',
            'help' => 'Kolor tego, co jest w toku: pasek paliwa na ekranie baku, paski i pierścienie zleceń warsztatu, ramka panelu „w toku”, klejnot RideCoin i tabliczka klasy auta w górnym pasku.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
            'tip' => 'Lekko zabarwia też tło sceny, na której stoi auto.',
        ],
        'color_cyan_bg' => [
            'label' => 'Ciemny odcień drugiego akcentu',
            'help' => 'Kolor tekstu i ikon na płytkach w drugim akcencie: tabliczka klasy auta w górnym pasku i zegarek zlecenia, które czeka w kolejce. Mimo nazwy „bg” nie jest tłem.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_ok' => [
            'label' => 'Powodzenie (zielony)',
            'help' => 'Kolor tego, co się udało: wypełnienie paska paliwa w górnym pasku, przycisk „+” przy baku, plus przy wzroście statystyk, ramka założonej części i znaczniki „OK”.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_danger' => [
            'label' => 'Błąd i ostrzeżenie (czerwony)',
            'help' => 'Kolor błędów i alarmów: komunikaty o błędach, mało paliwa, zużyta część, przyciski usuwania i minus przy spadku statystyk.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_r1' => [
            'label' => 'Rzadkość 1: zwykła',
            'help' => 'Kolor najniższej rzadkości części (w grze „Zwykła”): nazwa rzadkości, kwadraciki, ramka kafelka części i znacznik części na aucie.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
            'tip' => 'Jest też kolorem napisów nieaktywnych zakładek (Auto | Flota, Sklep | Giełda | Dealer).',
        ],
        'color_r2' => [
            'label' => 'Rzadkość 2: niezwykła',
            'help' => 'Kolor rzadkości „Niezwykła”: nazwa rzadkości, kwadraciki, ramka kafelka części i znacznik części na aucie.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_r3' => [
            'label' => 'Rzadkość 3: rzadka',
            'help' => 'Kolor rzadkości „Rzadka”: nazwa rzadkości, kwadraciki, ramka kafelka części i znacznik części na aucie.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_r4' => [
            'label' => 'Rzadkość 4: epicka',
            'help' => 'Kolor rzadkości „Epicka”: nazwa rzadkości, kwadraciki, ramka kafelka części i znacznik części na aucie.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
        ],
        'color_r5' => [
            'label' => 'Rzadkość 5: legendarna',
            'help' => 'Kolor najwyższej rzadkości, „Legendarnej”: nazwa rzadkości, kwadraciki, ramka kafelka części i znacznik części na aucie.',
            'range' => '#rrggbb: sześć cyfr szesnastkowych. Puste pole: kolor aplikacji (:default).',
            'tip' => 'Domyślnie ma ten sam kolor co akcent, więc legendarne części świecą jak przyciski. Zmień go osobno, jeśli akcent ma być inny.',
        ],
        'font_display' => [
            'label' => 'Krój nagłówków',
            'help' => 'Krój tytułów, przycisków, nazw aut i dużych liczb (w aplikacji jest wąski, gruby i pisany wielkimi literami). Puste pole: krój aplikacji.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'Wąski, mocny krój aplikacji, domyślny dla nagłówków. Plik jest w pakiecie aplikacji.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'Czytelny krój tekstu aplikacji, domyślny dla zwykłego tekstu. Plik jest w pakiecie aplikacji.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'Krój o stałej szerokości znaków, domyślny dla liczb. Plik jest w pakiecie aplikacji.'],
                'system-sans' => ['label' => 'Systemowy bezszeryfowy', 'help' => 'Krój bezszeryfowy urządzenia gracza (np. Segoe UI, Roboto); na każdym urządzeniu wygląda trochę inaczej.'],
                'system-serif' => ['label' => 'Systemowy szeryfowy', 'help' => 'Krój szeryfowy urządzenia gracza (Georgia albo Times New Roman).'],
                'system-mono' => ['label' => 'Systemowy o stałej szerokości', 'help' => 'Krój o stałej szerokości znaków z urządzenia gracza (Consolas, Courier New albo podobny).'],
            ],
        ],
        'font_sans' => [
            'label' => 'Krój tekstu',
            'help' => 'Krój zwykłego tekstu: opisów, akapitów, komunikatów i pól do wpisywania. Puste pole: krój aplikacji.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'Wąski, mocny krój aplikacji, domyślny dla nagłówków. Plik jest w pakiecie aplikacji.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'Czytelny krój tekstu aplikacji, domyślny dla zwykłego tekstu. Plik jest w pakiecie aplikacji.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'Krój o stałej szerokości znaków, domyślny dla liczb. Plik jest w pakiecie aplikacji.'],
                'system-sans' => ['label' => 'Systemowy bezszeryfowy', 'help' => 'Krój bezszeryfowy urządzenia gracza (np. Segoe UI, Roboto); na każdym urządzeniu wygląda trochę inaczej.'],
                'system-serif' => ['label' => 'Systemowy szeryfowy', 'help' => 'Krój szeryfowy urządzenia gracza (Georgia albo Times New Roman).'],
                'system-mono' => ['label' => 'Systemowy o stałej szerokości', 'help' => 'Krój o stałej szerokości znaków z urządzenia gracza (Consolas, Courier New albo podobny).'],
            ],
        ],
        'font_mono' => [
            'label' => 'Krój liczb',
            'help' => 'Krój liczb i małych podpisów: chipów w górnym pasku, etykiet nad polami i znaczników. Puste pole: krój aplikacji.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'Wąski, mocny krój aplikacji, domyślny dla nagłówków. Plik jest w pakiecie aplikacji.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'Czytelny krój tekstu aplikacji, domyślny dla zwykłego tekstu. Plik jest w pakiecie aplikacji.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'Krój o stałej szerokości znaków, domyślny dla liczb. Plik jest w pakiecie aplikacji.'],
                'system-sans' => ['label' => 'Systemowy bezszeryfowy', 'help' => 'Krój bezszeryfowy urządzenia gracza (np. Segoe UI, Roboto); na każdym urządzeniu wygląda trochę inaczej.'],
                'system-serif' => ['label' => 'Systemowy szeryfowy', 'help' => 'Krój szeryfowy urządzenia gracza (Georgia albo Times New Roman).'],
                'system-mono' => ['label' => 'Systemowy o stałej szerokości', 'help' => 'Krój o stałej szerokości znaków z urządzenia gracza (Consolas, Courier New albo podobny).'],
            ],
        ],
        'css' => [
            'label' => 'Własny arkusz stylów (CSS)',
            'help' => 'Reguły CSS, które idą po arkuszu aplikacji i mają ostatnie słowo, więc mogą zmienić wygląd dowolnego elementu. Arkusz nie może niczego ładować z zewnątrz: odrzucane są @import, @font-face, @namespace, image-set(), src(), image(), element(), cross-fade(), paint(), attr(), expression(), javascript:, behavior:, -moz-binding, każde url() poza obrazkiem gry (/api/v1/media/nazwa) oraz odwrotny ukośnik poza tekstem w cudzysłowie.',
            'example' => '.ttl { text-shadow: none; } zdejmuje cień spod tytułów ekranów.',
            'range' => 'Najwyżej 20 000 bajtów (litera spoza ASCII to 2 do 4 bajtów).',
            'tip' => 'Arkusz jest czytany tak jak w przeglądarce: komentarze i teksty w cudzysłowie są pomijane, więc content: "\201C" jest dozwolone. Arkusz, który nie przechodzi, nie jest zapisywany, a komunikat podaje powód każdej odrzuconej rzeczy; nigdy nie jest przyjmowany częściowo. Obrazek w url() musi już być w magazynie obrazków gry (wgrywa się go w panelu), a jego nazwa to 64 znaki szesnastkowe z rozszerzeniem png, webp albo jpg.',
        ],
        'blocks' => [
            'label' => 'Bloki strony',
            'help' => 'Strona pod adresem domeny gry, od góry do dołu. Dodawaj bloki i przeciągaj je, żeby zmienić kolejność; jest ich najwyżej 12. Bez żadnego bloku gra pokazuje stronę startową aplikacji.',
            'tip' => 'Rodzaje bloków: Góra strony (nadtytuł, tytuł, podtytuł, obrazek i przyciski startu), Karty (od 1 do 6), Tekst (tytuł i akapity), Obrazek (z opisem i podpisem) oraz Przyciski (tytuł, zdanie i przyciski startu). Przyciski „Utwórz kierowcę” i „Kontynuuj” dodaje sama aplikacja, a blok, w którym brakuje tego, co konieczne (np. tytułu), jest pomijany.',
        ],
        'items' => [
            'label' => 'Karty',
            'help' => 'Od 1 do 6 kart w jednym bloku, obok siebie (na wąskim ekranie jedna pod drugą). Każda ma znacznik (mały podpis nad tytułem, opcjonalny), tytuł i tekst.',
        ],
        'text_eyebrow' => [
            'label' => 'Nadtytuł',
            'help' => 'Mały napis wielkimi literami nad głównym tytułem. Wpisz go w językach, w których gra ma mówić; angielski i polski są wymagane, gdy pole ma tekst w którymkolwiek języku, a język bez tekstu pokazuje angielski.',
            'example' => 'Gra przeglądarkowa · idle · wyścigi (strona startowa aplikacji).',
            'range' => 'Do :limit znaków w każdym języku.',
        ],
        'text_title' => [
            'label' => 'Tytuł',
            'help' => 'Nagłówek: w bloku Góra strony to duży tytuł strony (wymagany), na karcie tytuł karty (wymagany), a w blokach Tekst i Przyciski nagłówek nad treścią (opcjonalny). Angielski i polski są wymagane, gdy pole ma tekst; język bez tekstu pokazuje angielski.',
            'example' => 'Dziupla, która ściga się, gdy Cię nie ma (strona startowa aplikacji).',
            'range' => 'Do :limit znaków w każdym języku.',
        ],
        'text_subtitle' => [
            'label' => 'Podtytuł',
            'help' => 'Zdanie pod tytułem w bloku Góra strony, bezpośrednio nad przyciskami startu. Angielski i polski są wymagane, gdy pole ma tekst; język bez tekstu pokazuje angielski.',
            'range' => 'Do :limit znaków w każdym języku.',
        ],
        'text_alt' => [
            'label' => 'Opis obrazka',
            'help' => 'Opis obrazka dla osób, które go nie widzą, i na wypadek, gdy obrazek się nie załaduje. W bloku Obrazek jest wymagany, w bloku Góra strony opcjonalny.',
            'example' => 'Dziupla w Złomowie o zmierzchu i trzy wraki na podwórku.',
            'range' => 'Do :limit znaków w każdym języku.',
        ],
        'text_kicker' => [
            'label' => 'Znacznik karty',
            'help' => 'Mały napis wielkimi literami nad tytułem karty. Opcjonalny; angielski i polski są wymagane, gdy pole ma tekst, a język bez tekstu pokazuje angielski.',
            'example' => 'Dobór, Rozwój, Idle (karty strony startowej aplikacji).',
            'range' => 'Do :limit znaków w każdym języku.',
        ],
        'text_text' => [
            'label' => 'Tekst',
            'help' => 'Krótki tekst: na karcie opis pod tytułem (wymagany), w bloku Przyciski zdanie pod tytułem (opcjonalne). To zwykły tekst, nigdy HTML. Język bez tekstu pokazuje angielski.',
            'range' => 'Do :limit znaków w każdym języku.',
        ],
        'text_body' => [
            'label' => 'Treść',
            'help' => 'Treść bloku Tekst. Pusta linia zaczyna nowy akapit; wszystko jest zwykłym tekstem, nigdy HTML-em. Angielski i polski są wymagane; język bez tekstu pokazuje angielski.',
            'range' => 'Do :limit znaków w każdym języku.',
        ],
        'text_caption' => [
            'label' => 'Podpis',
            'help' => 'Podpis pod obrazkiem w bloku Obrazek. Opcjonalny; angielski i polski są wymagane, gdy pole ma tekst, a język bez tekstu pokazuje angielski.',
            'range' => 'Do :limit znaków w każdym języku.',
        ],
        'text_description' => [
            'label' => 'Opis podglądu linku',
            'help' => 'Zdanie pod tytułem w podglądzie, który czat albo serwis społecznościowy robi z adresu gry. Podgląd jest jeden: bierze tekst w języku gry (z jej ustawień) albo angielski, jeśli tego języka brak, więc pozostałe języki nie są czytane. Puste pole: nazwa gry.',
            'range' => 'Do :limit znaków w każdym języku.',
        ],
        'share_title' => [
            'label' => 'Tytuł podglądu linku',
            'help' => 'Tytuł, który pokazuje czat albo serwis społecznościowy, gdy ktoś wklei adres gry. To jeden tekst, bez języków. Puste pole: nazwa gry.',
            'range' => 'Do 70 znaków.',
        ],
        'image_upload' => [
            'label' => 'Obrazek',
            'help' => 'Obrazek bloku. Wybierz plik, a zapis szkicu zachowa go w magazynie obrazków i wpisze jego nazwę do bloku; bez wyboru zostaje obecny obrazek.',
            'range' => 'PNG, WebP albo JPEG, do 1 MB, bok od :min do :max pikseli.',
            'tip' => 'W bloku Góra strony obrazek jest rozciągany na całą szerokość jako pas o wysokości 220 px (na szerokim ekranie 340 px) i przycinany, więc ważne rzeczy trzymaj na środku. W bloku Obrazek jest pokazany w całości na pełnej szerokości.',
        ],
        'share_image_upload' => [
            'label' => 'Obrazek podglądu',
            'help' => 'Obrazek w podglądzie linku, zwykle 1200 × 630 pikseli. Wybierz plik, a zapis szkicu zachowa go w magazynie obrazków; bez wyboru zostaje obecny obrazek.',
            'range' => 'PNG, WebP albo JPEG, do 1 MB, bok od :min do :max pikseli.',
            'tip' => 'Bez własnego obrazka podgląd bierze ikonę gry; gra bez ikony nie ma w podglądzie obrazka.',
        ],
        'image_remove' => [
            'label' => 'Zdejmij obrazek',
            'help' => 'Włącz i zapisz szkic, żeby blok (albo podgląd linku) nie miał już obrazka. Plik wybrany w polu obok ma pierwszeństwo. Widać go tylko wtedy, gdy obrazek jest ustawiony.',
            'tip' => 'W bloku Obrazek obrazek jest wymagany, więc zapis bez niego zostanie odrzucony.',
        ],
    ],
];
