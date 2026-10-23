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

Z tego pliku zamrażana jest konfiguracja biegu: tor (wagi i wartości odniesienia statystyk, czas bazowy, nagroda, XP, zużycie paliwa), klimat regionu (szanse pogody w odcinku 22,5 min i ziarno), efekt każdej pogody, cena paliwa, koszt naprawy i szansa awarii na okrążenie. Liczby pochodzą z płótna projektowego (tor Złomowo, klimat regionu, cena paliwa 2 $/l) i z wektorów rozliczania (okrążenie 75 s, 15,50 $, 1,5 l). XP za okrążenie to 2, z kotwic ekonomii (90 XP na godzinę, 48 okrążeń na godzinę, czyli 1,875, w pełnych liczbach 2): wektory rozliczania mają 25, ale to parametr testów, nie liczba gry. Z 2 XP deszcz (93%) nie zmienia już XP okrążenia, bo 1,86 zaokrągla się do 2. Wartości bez źródła w projekcie (koszt naprawy, szansa awarii, holowanie) są tymczasowe i czekają na kalibrację w symulatorze ekonomii.

`tow` to wyjście dla kierowcy, którego nie stać na ruszenie stojącego auta (naprawa po awarii albo paliwo na jedno okrążenie): holowanie jest darmowe, ale auto czeka na nie `delay_ms` i dostaje `fuel_laps` okrążeń paliwa, jeśli bak jest pusty. Czas czekania musi kosztować więcej utraconego zarobku, niż kosztowałaby naprawa z paliwem, żeby holowanie nie stało się sposobem na unikanie opłat. Pilnuje tego `StarterRulesTest`.

Kroki samouczka są w osobnym pliku, `rules/tutorial.json` (schemat `tutorial.schema.json`): kolejność, fakt rozpoczynający i kończący krok, SMS-y Kuby w pięciu językach, przycisk odpowiedzi, element do podświetlenia i nagroda w gotówce. Opisuje je `docs/api-runs.md`.

`car`, `shop` i `workshop` opisują własne auta, sklep z częściami i warsztat. Część tieru T da się zamontować w aucie od poziomu `levels_per_tier × (T − 1)` (tier ≤ 1 + ⌊poziom / 3⌋), a nowe auto ma poziom `start_level`. Auto zbiera XP z okrążeń (`xp_per_lap`: 10 na okrążenie w każdej pogodzie), pasmo od poziomu *n* do *n+1* ma `xp_per_level · n` XP (200: trzecie pasmo to 600, jak na planszy Garaż), a poziom kończy się na `max_level` (30; tier 10 odblokowuje poziom 27, co pilnuje `StarterRulesTest`). Liczby są wstępne, projekt ich nie podaje. `stat_ceiling` to wartość każdej statystyki, przy której jej pasek w garażu jest pełny: to, co ma osiągać najlepsze auto całej gry, a nie to, czego chce tor (od tego są wartości odniesienia toru). Wrak startowy nie może mieć więcej niż połowy sufitu żadnej statystyki, pilnuje tego test; liczby (przyspieszenie 400 kW/t, prędkość 450 km/h, przyczepność, prowadzenie i hamowanie po 250) są wstępne i do sprawdzenia w symulatorze ekonomii. Sklep sprzedaje części z listy `shop.parts` po cenie z definicji (bez rzadkości) i **tylko takie, które coś zmieniają w statystykach auta**: opony T2, zawieszenie, hamulce i wydech; reszta paczki (zbiornik, chłodzenie, skrzynia, opony terenowe i deszczowe) czeka na swoje mechaniki. Warsztat ma jednego mechanika bez wyposażenia: zlecenie trwa `base_time_ms` slotu (dla tieru 1) razy tier, skrócone o 2% za poziom mechanika, a zdjęcie części to 60% czasu założenia (`removal_ppm`). W kolejce mieści się `max_queue` zleceń. Czasy są robocze: wzięte z przykładów na płótnie (opony T3 ok. 6 min, zawieszenie T3 ok. 14 min tam, tu krótsze) i czekają na kalibrację w symulatorze ekonomii. Czas zlecenia liczy `Rules::workshopJobMs` na liczbach całkowitych.

- Mnożniki są w ppm (850 000 to 0,85), wagi toru w procentach i sumują się do 100, szanse pogody sumują się do 1 000 000.
- Wartości odniesienia toru Złomowo to statystyki fabrycznego Rdzaka GT-4 na oponach `stock_tires`, więc to auto jeździ tam dokładnie czas bazowy.
- Efekty pogody zakładają opony fabryczne. Gdy dojdzie wybór opon, efekt wyliczy się z macierzy opon i nawierzchni.
- Schemat to `schema/rules.schema.json`, a zależności, których schemat nie wyrazi (sumy, regiony, tłumaczenia), sprawdza test `StarterRulesTest`.

## Ekonomia i symulator (`rules/economy.json`)

`economy.json` (schemat `economy.schema.json`) to **kotwice ekonomii** z Systemy V i VI projektu: to, co się wpisuje, a z czego wynika reszta. Czyta je symulator ekonomii v0, polecenie `php artisan economy:simulate` (kod w `api/app/Domain/Economy/`, czysty, bez zegara i losowości, więc ten sam zestaw reguł zawsze daje te same dni).

**Kotwice:** zysk netto na godzinę jazdy na szczeblu *k* drabiny aut to `600 · 1,15^k`; drabina ma dziewięć szczebli (L0 to naprawiony wrak za 2 000 $, L8 hiperauto za 780 000 $) z wartością auta, minimalnym poziomem gracza, okrążeniami na godzinę, paliwem na okrążenie i oknem offline (jak długo auto jedzie na jednym baku); ceny nowych aut dla tierów T1–T10 i udział zestawu części (50% ceny auta) z udziałem każdego gniazda. Z nich wynikają: nagroda za okrążenie (`zysk / okrążenia + paliwo · cena`), czas zwrotu auta, koszt kroku w górę (`wartość następnego auta − 70% wartości starego`) i ceny części. **Pokrętło** `knobs.reward_mult` mnoży zysk (zakres 0,7–1,4, jak regulator z projektu).

**Gracze** to trzy typy z projektu: A regularny (3 wizyty dziennie, do 14 h jazdy, aktywność ×1,25), B okazjonalny (1 wizyta, do 12 h, ×1,00) i C intensywny (8 wizyt, do 18 h, ×1,60). Godziny jazdy w dniu to `min(limit, wizyty · okno offline auta)`, dochód to `zysk/h · godziny · aktywność · 90% (serwis i naprawy 10%) · flota`, a XP to `90 · aktywność` na godzinę. Poziom gracza liczy ta sama krzywa co w grze, `45 · n^2,3`. Start to 1 200 $ z nagród samouczka.

**Symulacja** idzie dzień po dniu: dochód i XP z tego dnia, a gdy gotówka pokrywa krok i poziom jest taki, jakiego chce dealer, kierowca kupuje (w tym samym dniu może kupić dwa auta). Dla każdego zakupu zapisuje, na co czekał: gotówkę, poziom czy oba naraz.

**Bramka tempa:** dzień zakupu każdego z ośmiu aut (L1–L8) dla każdego z trzech graczy musi mieścić się w ±25% dnia z projektu (`targets`, 24 liczby). Dzień to rozdzielczość modelu, więc cel jednodniowy lub dwudniowy dopuszcza też dzień w każdą stronę (`gate.slack_days`). Polecenie kończy się kodem 1, gdy któraś liczba jest poza bramką, a kodem 2, gdy zestaw reguł nie daje się wczytać (brak pliku, nie JSON, zła zgodność ze schematem, cele niepasujące do graczy), więc nadaje się do CI.

```
php artisan economy:simulate                       # zestaw z repozytorium, wszystkie trzy typy
php artisan economy:simulate --player=B --json     # tylko B, raport jako JSON
php artisan economy:simulate --ruleset=moj.json    # inny zestaw reguł
php artisan economy:simulate --strict              # kod 1 także, gdy liczba gry odbiega od kotwic
```

**Zgodność reguł gry z kotwicami.** Pod bramką polecenie zestawia liczby, na których działa gra (`starter.json` i paczka `packs/core`), z tymi, które dają kotwice, w tolerancji `consistency.tolerance` (20%, jak „seeder sprawdza ±20%” w projekcie): czas okrążenia toru startowego, paliwo na okrążenie, cenę paliwa, nagrodę za okrążenie, XP za okrążenie i cenę każdej części paczki. To raport; kod 1 daje dopiero `--strict`.

**Co pokazał pierwszy przebieg** (zestaw `2026.10.1`):

- Wzory z projektu odtwarzają **wszystkie 24 dni z tabeli celów co do dnia** i tabelę zysków (nagroda za okrążenie, czas zwrotu, dochód dzienny A z flotą od 1 do 1,7 auta). Bramka przechodzi.
- **Tempo wyznacza poziom gracza, nie gotówka.** W każdym zakupie (poza pierwszym C, gdzie oba naraz) kierowca czeka na poziom, a pieniądze są już na koncie. Wniosek dla regulatorów: `reward_mult` w dozwolonym zakresie 0,7–1,4 **w ogóle nie zmienia tempa** (od 0,5 w dół dopiero zaczyna), a tempem steruje XP na godzinę: bramka przechodzi dla 75–105 XP/h (cel 90), przy 60 XP/h pada prawie wszystko, a przy 120 jedna liczba. Regulator „tempo zakupu aut”, który miałby kręcić nagrodami, w tym modelu nie działa; do rozważenia przy kalibracji, bo projekt mówi, że „na starcie decyduje gotówka”.
- **XP za okrążenie w grze było o rząd wielkości za duże, i zostało skalibrowane.** Kotwica daje 90 XP/h, czyli 1,9 XP na okrążenie toru startowego, a `starter.json` miał 25 (parametr wektorów testowych rozliczania, przeniesiony do reguł). Z 25 XP na okrążenie (1 200 XP/h) bramka padała w 22 z 24 liczb, a gracz A kupował ostatnie auto w dniu 35 zamiast 76. Teraz jest 2 XP na okrążenie (96 XP/h, +7% względem kotwicy) i `economy:simulate --strict` przechodzi: wszystkie liczby gry są w kotwicach (opony terenowe są o 9% powyżej, w tolerancji). Symulator gra z 90 XP/h z kotwic, a gra daje 96; z 96 gracz A kupuje ostatnie auto w dniu 71 zamiast 76, czyli nadal w bramce. Skutki dla gry: pierwszy poziom gracza trwa dłużej (drugi poziom to 222 XP, czyli ok. 2,3 h jazdy), a pasek XP w HUD rusza się powoli; samouczek i kroki z nagrodami w gotówce nie zależą od XP.

**Założenia v0, których projekt nie rozstrzyga** (do potwierdzenia):

- Flota dla B to jedno auto (jeździ „tyle, ile ma paliwa”), a dla C taka sama jak dla A. Na tempo nie ma to wpływu, bo gotówka nie jest ograniczeniem, ale będzie miało, gdy to się zmieni.
- Zakup następuje w dniu, w którym dochód i XP tego dnia wystarczają; pierwszy dzień liczy się w całości.
- Okno offline L0 to 0,5 h, a limity godzin (14, 12, 18) są granicą doby, nie liczbą wizyt razy okno.
- Tytuł „tempo” obejmuje tylko drabinę aut. Planowane przez projekt, a **jeszcze niezrobione**: druga bramka („żaden build nie wygrywa więcej niż dwóch torów”, wymaga torów i buildów, F2), regulatory z audytem i trybem cienia, wskaźniki zdrowia z telemetrii, `reward_mult` osobno dla klas D–X, `price_index_used`, tempo rzadkich części (cele projektowe bez modelu), rynek, kursy i turnieje (siedzą w mnożniku aktywności), VIP.

## Wersje i zmiany balansu

Zasady wersjonowania definicji, cyklu życia (szkic, aktywna, wycofana) i osłabień opisuje dokument „Treść, konto i płatności” na płótnie projektowym. Schemat v0 obejmuje auta i części; miasta, tory i zestawy reguł dojdą w kolejnych wersjach schematu.
