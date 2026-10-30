<?php

return [
    'entries' => [
        'slug' => [
            'label' => 'Slug gry',
            'help' => 'Krótka nazwa, którą polecenia, panel i dziennik wskazują tę grę. Wybierasz ją raz, przy tworzeniu gry.',
            'example' => 'statki, raceidle',
            'range' => '2–32 znaki: małe litery, cyfry i myślniki, pierwsza litera; inny dla każdej gry.',
            'tip' => 'Nie da się go potem zmienić, bo polecenia (--game=slug), skrypty wdrożenia, dziennik zmian i przełącznik gier w panelu nazywają grę właśnie tak. W kreatorze podpowiada się z nazwy; możesz go poprawić, zanim przejdziesz dalej.',
        ],
        'name' => [
            'label' => 'Nazwa gry',
            'help' => 'Jak gra się nazywa: na karcie przeglądarki, w pasku aplikacji, pod ikoną na ekranie głównym telefonu (tylko pierwsze słowa mieszczące się w 12 literach), w e-mailach i na ekranie startowym.',
            'example' => 'Statki Kosmiczne',
            'range' => 'Do 64 znaków.',
        ],
        'domain' => [
            'label' => 'Domena',
            'help' => 'Adres, pod którym odpowiada gra, małymi literami, bez http:// i bez ścieżki. Kto otworzy ten adres, trafi do tej gry. Bez domeny gra jest dostępna tylko z panelu (chyba że jest jedyną grą instalacji).',
            'example' => 'statki.example.com',
            'range' => 'Co najmniej dwa człony (gra.example.com), do 190 znaków; inna dla każdej gry.',
            'tip' => 'Zanim wystawi się certyfikat, domena musi wskazywać na ten serwer (rekord A, AAAA dla IPv6). Po ustawieniu albo zmianie otwórz stronę „Domena i SSL” tej gry: sprawdza DNS i zleca serwerowi ustawienie adresu i certyfikatu SSL. Gra w szkicu nie odpowiada na swojej domenie także wtedy.',
        ],
        'locale' => [
            'label' => 'Język gry',
            'help' => 'Język, w którym nowi gracze widzą grę, dopóki nie wybiorą własnego; jest też językiem strony i aplikacji gry, gdy język odwiedzającego nie jest jednym z języków gry.',
            'options' => [
                'pl' => ['label' => 'Polski', 'help' => 'Nowi gracze widzą grę po polsku, dopóki nie wybiorą innego języka.'],
                'en' => ['label' => 'English', 'help' => 'Nowi gracze widzą grę po angielsku, dopóki nie wybiorą innego języka.'],
                'fr' => ['label' => 'Français', 'help' => 'Nowi gracze widzą grę po francusku, dopóki nie wybiorą innego języka.'],
                'de' => ['label' => 'Deutsch', 'help' => 'Nowi gracze widzą grę po niemiecku, dopóki nie wybiorą innego języka.'],
                'pt-BR' => ['label' => 'Português (Brasil)', 'help' => 'Nowi gracze widzą grę po portugalsku (brazylijskim), dopóki nie wybiorą innego języka.'],
            ],
        ],
        'theme_color' => [
            'label' => 'Kolor ramki',
            'help' => 'Kolor ramki wokół gry: pasek przeglądarki i telefonu, ekran startowy zainstalowanej aplikacji i podgląd linku. Kolory wewnątrz gry ustawiasz na stronie „Marka i strona”.',
            'example' => '#0e1013 (ciemny kolor gry samochodowej)',
            'range' => 'Sześć cyfr szesnastkowych z krzyżykiem, np. #0e1013.',
        ],
        'icon_upload' => [
            'label' => 'Ikona',
            'help' => 'Kwadratowy obrazek (PNG, WebP lub JPEG), co najmniej :min × :min pikseli, najlepiej 512: ikona aplikacji na ekranie głównym i na karcie przeglądarki. Bez niej gra zostaje przy ikonie gry samochodowej.',
            'range' => 'Kwadrat, co najmniej :min px z boku, do 2048 px i 1 MB.',
            'tip' => 'Wybrany plik zastępuje obecną ikonę dopiero po zapisaniu gry; obrazek, który nie jest kwadratem albo ma mniej niż :min px z boku, jest odrzucany i nic się nie zapisuje.',
        ],
        'icon_remove' => [
            'label' => 'Zdejmij ikonę',
            'help' => 'Po zapisaniu gra wraca do ikony gry samochodowej.',
        ],
        'icon_maskable_upload' => [
            'label' => 'Ikona z marginesem',
            'help' => 'Ten sam obrazek z szerokim marginesem, tak by to, co ważne, zostało w środkowych 80%: dla telefonów, które przycinają ikony do kółek albo zaokrąglonych kwadratów. Opcjonalna; bez niej używana jest ikona powyżej, jaka jest. Kwadrat, co najmniej :min px.',
        ],
        'icon_maskable_remove' => [
            'label' => 'Zdejmij ikonę z marginesem',
            'help' => 'Po zapisaniu telefony znów używają zwykłej ikony z góry.',
        ],
        'status' => [
            'label' => 'Status',
            'help' => 'Czy gra wpuszcza graczy. Szkic nie odpowiada na swojej domenie; uruchom grę, gdy jej treść (paczka) i domena są gotowe.',
            'tip' => 'Pusta gra potrzebuje paczki, zanim da się ją uruchomić. Nowa gra zawsze zaczyna jako szkic.',
            'options' => [
                'live' => ['label' => 'Działa', 'help' => 'Gra odpowiada na swojej domenie, a gracze mogą zakładać konta i grać.'],
                'draft' => ['label' => 'Szkic', 'help' => 'Gra jest w robocie: nie odpowiada nikomu na swojej domenie i da się nad nią pracować tylko z panelu.'],
            ],
        ],
        'is_default' => [
            'label' => 'Gra domyślna',
            'help' => 'Gra, która odpowiada tam, gdzie nic nie mówi, o którą chodzi: polecenia bez --game i nieznany host w środowisku developerskim. Tu się jej nie zmienia.',
            'tip' => 'Na serwerze produkcyjnym adres, którego nie zajęła żadna działająca gra, nie odpowiada nikomu, a nie grze domyślnej. Gra domyślna, dopóki nie ma żywej paczki, gra z plików treści z repozytorium.',
        ],
    ],
];
