# Paczki treści

Treść gry (auta, części, w przyszłości miasta, tory i zestawy reguł) żyje w plikach JSON, nie w kodzie. Panel admina i seedery pracują na tych samych plikach, a każda paczka ma numer i jest walidowana przed wdrożeniem.

```
content/
  schema/            schematy JSON (draft 2020-12), wersja 0
  packs/
    core/
      pack.json          manifest: schema, name, version, description
      vehicles/<slug>.json
      parts/<slug>.json
  rules/
    starter.json         zasady biegu: tory, klimat regionu, efekty pogody, ceny
```

## Zasady

- **Jedna definicja to jeden plik**, nazwany tak jak jego `slug` (`rdzak-gt-4.json`). Slug jest niezmienny i nigdy nie jest używany ponownie. Zmiana nazwy wyświetlanej to zmiana pola `name`, nie slugu.
- **Nazwy i opisy** są w pięciu językach: `en`, `pl` (wymagane), `fr`, `de`, `pt-BR`. Brak pozostałych jest ostrzeżeniem, a przy `--strict` błędem. Paczka startowa musi być kompletna.
- **Pieniądze** są liczbą całkowitą w centach (`price_cents`), np. $21 500 to `2150000`.
- **Poziom u dealera** wynika z tieru: `min_player_level = max(1, 3 × tier − 3)`.
- **Auto spalinowe** ma silnik (`layout`, `displacement_l`, `tune`) i `tank_l`. **Elektryczne** ma `motors`, `battery_kwh` i `max_charge_kw`, a nie ma baku.
- Statystyki części (`stats`) to liczby; rzadkość i ulepszenia mnożą je w czasie działania gry.

## Walidacja

```sh
cd api
php artisan content:validate            # paczka packs/core
php artisan content:validate ../content/packs/core --strict
```

Komenda sprawdza schemat, zgodność nazwy pliku ze slugiem, poziom dealera i tłumaczenia. Test `CorePackTest` uruchamia ją na paczce startowej, więc CI zatrzymuje zmianę, która psuje paczkę.

## Zasady biegu (`rules/starter.json`)

Z tego pliku zamrażana jest konfiguracja biegu: tor (wagi i wartości odniesienia statystyk, czas bazowy, nagroda, XP, zużycie paliwa), klimat regionu (szanse pogody w odcinku 22,5 min i ziarno), efekt każdej pogody, cena paliwa, koszt naprawy i szansa awarii na okrążenie. Liczby pochodzą z płótna projektowego (tor Złomowo, klimat regionu, cena paliwa 2 $/l) i z wektorów rozliczania (okrążenie 75 s, 15,50 $, 25 XP, 1,5 l). Wartości bez źródła w projekcie (koszt naprawy, szansa awarii, holowanie) są tymczasowe i czekają na kalibrację w symulatorze ekonomii.

`tow` to wyjście dla kierowcy, którego nie stać na ruszenie stojącego auta (naprawa po awarii albo paliwo na jedno okrążenie): holowanie jest darmowe, ale auto czeka na nie `delay_ms` i dostaje `fuel_laps` okrążeń paliwa, jeśli bak jest pusty. Czas czekania musi kosztować więcej utraconego zarobku, niż kosztowałaby naprawa z paliwem, żeby holowanie nie stało się sposobem na unikanie opłat. Pilnuje tego `StarterRulesTest`.

`car`, `shop` i `workshop` opisują własne auta, sklep z częściami i warsztat. Część tieru T da się zamontować w aucie od poziomu `levels_per_tier × (T − 1)` (tier ≤ 1 + ⌊poziom / 3⌋), a nowe auto ma poziom `start_level`. `stat_ceiling` to wartość każdej statystyki, przy której jej pasek w garażu jest pełny: to, co ma osiągać najlepsze auto całej gry, a nie to, czego chce tor (od tego są wartości odniesienia toru). Wrak startowy nie może mieć więcej niż połowy sufitu żadnej statystyki, pilnuje tego test; liczby (przyspieszenie 400 kW/t, prędkość 450 km/h, przyczepność, prowadzenie i hamowanie po 250) są wstępne i do sprawdzenia w symulatorze ekonomii. Sklep sprzedaje części z listy `shop.parts` po cenie z definicji (bez rzadkości) i **tylko takie, które coś zmieniają w statystykach auta**: opony T2, zawieszenie, hamulce i wydech; reszta paczki (zbiornik, chłodzenie, skrzynia, opony terenowe i deszczowe) czeka na swoje mechaniki. Warsztat ma jednego mechanika bez wyposażenia: zlecenie trwa `base_time_ms` slotu (dla tieru 1) razy tier, skrócone o 2% za poziom mechanika, a zdjęcie części to 60% czasu założenia (`removal_ppm`). W kolejce mieści się `max_queue` zleceń. Czasy są robocze: wzięte z przykładów na płótnie (opony T3 ok. 6 min, zawieszenie T3 ok. 14 min tam, tu krótsze) i czekają na kalibrację w symulatorze ekonomii. Czas zlecenia liczy `Rules::workshopJobMs` na liczbach całkowitych.

- Mnożniki są w ppm (850 000 to 0,85), wagi toru w procentach i sumują się do 100, szanse pogody sumują się do 1 000 000.
- Wartości odniesienia toru Złomowo to statystyki fabrycznego Rdzaka GT-4 na oponach `stock_tires`, więc to auto jeździ tam dokładnie czas bazowy.
- Efekty pogody zakładają opony fabryczne. Gdy dojdzie wybór opon, efekt wyliczy się z macierzy opon i nawierzchni.
- Schemat to `schema/rules.schema.json`, a zależności, których schemat nie wyrazi (sumy, regiony, tłumaczenia), sprawdza test `StarterRulesTest`.

## Wersje i zmiany balansu

Zasady wersjonowania definicji, cyklu życia (szkic, aktywna, wycofana) i osłabień opisuje dokument „Treść, konto i płatności” na płótnie projektowym. Schemat v0 obejmuje auta i części; miasta, tory i zestawy reguł dojdą w kolejnych wersjach schematu.
