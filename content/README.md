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

`car`, `shop` i `workshop` opisują własne auta, sklep z częściami i warsztat. Część tieru T da się zamontować w aucie od poziomu `levels_per_tier × (T − 1)` (tier ≤ 1 + ⌊poziom / 3⌋), a nowe auto ma poziom `start_level`. Auto zbiera XP z okrążeń (`xp_per_lap`: 10 na okrążenie w każdej pogodzie), pasmo od poziomu *n* do *n+1* ma `xp_per_level · n` XP (200: trzecie pasmo to 600, jak na planszy Garaż), a poziom kończy się na `max_level` (30; tier 10 odblokowuje poziom 27, co pilnuje `StarterRulesTest`). Liczby są wstępne, projekt ich nie podaje. `stat_ceiling` to wartość każdej statystyki, przy której jej pasek w garażu jest pełny: to, co ma osiągać najlepsze auto całej gry, a nie to, czego chce tor (od tego są wartości odniesienia toru). Wrak startowy nie może mieć więcej niż połowy sufitu żadnej statystyki, pilnuje tego test; liczby (przyspieszenie 400 kW/t, prędkość 450 km/h, przyczepność, prowadzenie i hamowanie po 250, terenowość 200) są wstępne i do sprawdzenia w symulatorze ekonomii. Sklep sprzedaje części z listy `shop.parts` po cenie z definicji (bez rzadkości) i **tylko takie, które coś zmieniają w jeździe auta**: opony (uliczne T2, całoroczne T1, terenowe T2, deszczowe T3, zimowe T3), zawieszenie (sportowe T1, rajdowe T2), hamulce i wydech; reszta paczki (zbiornik, chłodzenie, skrzynia, slick T7) czeka na swoje mechaniki. Sklep może sprzedawać część o jeden tier wyżej, niż montuje nowe auto (deszczowe i zimowe T3): wtedy mówi, jakiego poziomu auta ona wymaga, i pilnuje tego `StarterRulesTest`. Warsztat ma jednego mechanika bez wyposażenia: zlecenie trwa `base_time_ms` slotu (dla tieru 1) razy tier, skrócone o 2% za poziom mechanika, a zdjęcie części to 60% czasu założenia (`removal_ppm`). W kolejce mieści się `max_queue` zleceń. Czasy są robocze: wzięte z przykładów na płótnie (opony T3 ok. 6 min, zawieszenie T3 ok. 14 min tam, tu krótsze) i czekają na kalibrację w symulatorze ekonomii. Czas zlecenia liczy `Rules::workshopJobMs` na liczbach całkowitych.

- Mnożniki są w ppm (850 000 to 0,85), wagi toru w procentach i sumują się do 100, szanse pogody sumują się do 1 000 000.
- Wartości odniesienia toru Złomowo to statystyki fabrycznego Rdzaka GT-4 na oponach `stock_tires`, więc to auto jeździ tam dokładnie czas bazowy.
- Efekt pogody na przyczepność, prowadzenie i hamowanie wylicza się z macierzy opon i nawierzchni (część „Tory, nawierzchnie i macierz opon” niżej), a `stat_ppm` pogody to to, co pogoda robi sama (ulewa: ×0,9 na przyczepności, mgła: ×0,92 na prędkości maksymalnej).
- Schemat to `schema/rules.schema.json`, a zależności, których schemat nie wyrazi (sumy, regiony, tłumaczenia), sprawdza test `StarterRulesTest`.

## Tory, nawierzchnie i macierz opon

Z projektu (Systemy I i II, plansza Mapa) są **pięć torów** z profilami, **pięć klimatów** i **macierz opon**. Wszystko jest w `rules/starter.json`; schemat pilnuje kształtu, a `StarterRulesTest` zależności.

| tor | region | nawierzchnia | poziom gracza | czas bazowy | nagroda | XP | paliwo | co waży (%) |
|---|---|---|---|---|---|---|---|---|
| `zlomowo-street` | Złomowo | asfalt | 1 | 75 s | 15,50 $ | 2 | 1,5 l | przysp. 20, prędk. 15, przycz. 25, prow. 25, ham. 15 |
| `kruszyn-quarry` | Kruszyn | żwir | 3 | 95 s | 22,00 $ | 2 | 1,9 l | przysp. 5, przycz. 25, prow. 25, ham. 10, teren 35 |
| `suchy-pas-drag` | Suchy Pas | asfalt | 5 | 45 s | 11,70 $ | 1 | 0,9 l | przysp. 50, prędk. 15, przycz. 25, prow. 5, ham. 5 |
| `wilcza-przelecz-switchbacks` | Wilcza Przełęcz | asfalt | 8 | 85 s | 25,00 $ | 2 | 1,7 l | przysp. 15, prędk. 5, przycz. 20, prow. 35, ham. 25 |
| `autodrom-circuit` | Autodrom | asfalt | 12 | 80 s | 26,50 $ | 2 | 1,6 l | po 20 |

Z projektu są wagi (Systemy I, „Profil toru”), czasy bazowe tam, gdzie je podaje „Dowód” (drag 45 s, Kruszyn 95 s, autodrom 80 s), poziomy (plansza Mapa: Kruszyn 3, Suchy Pas 5, Wilcza Przełęcz 8, Autodrom 12) i szanse pogody (tabela „Klimat regionów”: np. Wilcza Przełęcz 25% sucho, 25% deszcz, 8% ulewa, 12% mgła, 5% śnieg). **Moje decyzje, których projekt nie rozstrzyga** (robocze):

- Czas bazowy Wilczej Przełęczy (85 s) i **zużycie paliwa**: tyle samo na sekundę jazdy co na torze startowym (20 ml/s).
- **Nagroda za okrążenie**: tyle, żeby godzina jazdy autem, do którego tor jest zrobiony, dawała dochód szczebla drabiny najbliższego poziomowi toru: (dochód netto godziny szczebla + paliwo na godzinę) × czas okrążenia, z kotwic ekonomii (L1 690 $/h dla Kruszyna, L2 794 dla Suchego Pasa, L3 913 dla Wilczej Przełęczy, L4 1050 dla Autodromu; Złomowo to L0, 600 $/h). Symulator ekonomii sprawdza tylko tor startowy, więc tych nagród jeszcze nie sprawdził.
- **XP za okrążenie** z kotwicy 90 XP na godzinę, w pełnych liczbach (2, 1, 2, 2).
- **Wartości odniesienia** (statystyki auta, które jeździ czas bazowy) to statystyki auta, do którego tor jest zrobiony: fabryczny Rdzak na Złomowie, Kuna 4x4 na oponach terenowych w kamieniołomie, Bizon na Suchym Pasie, Rdzak z zawieszeniem sportowym i oponami T2 na serpentynach, Bizon z zawieszeniem, hamulcami i oponami T2 na Autodromie. Są liczone z wpływem masy (niżej). Pilnuje tego `RunInputsFactoryTest` (każde z nich jedzie czas bazowy z dokładnością 1,5%).
- **Śnieg** (tylko Wilcza Przełęcz) płaci jak deszcz: ×0,93 nagrody i XP.
- **Masa auta wpływa na prowadzenie i hamowanie** (`car.mass` w regułach): oba są mnożone przez `(1000 kg / masa) ^ 0,6`, po uśrednieniu z zawieszeniem i hamulcami. Projekt mówi tylko „mniej masy to lepsze przyspieszenie i prowadzenie” (Systemy I) i nie podaje wzoru; wykładnik 0,6 i masa odniesienia 1000 kg (Rdzak, więc fabryczny Rdzak jest nietknięty) to moja decyzja, wybrana tak, żeby tory rozdały trzy auta. Bizon (1480 kg) dostaje ×0,79: jego prowadzenie spada z 88 do 69,6, a hamowanie z 90 do 71,1, i jest na tym pasku w garażu widać. Napędu (FWD, RWD, AWD z trakcją zależną od nawierzchni) jeszcze nie ma.
- **Poziom gracza otwiera tor** (`min_player_level`): serwer odmawia startu i zmiany toru poniżej niego (opis w `docs/api-runs.md`). Do porównań auto jest myślane jako poziomu toru, ale nie niższego niż poziom nowego auta.

**Nawierzchnie i macierz opon.** Tor ma `surface` (asfalt albo żwir), pogoda ma `ground` (sucho, mokro, śnieg): razem dają jedną z pięciu nawierzchni, czyli kolumn macierzy. Deszcz i ulewa mokrzą asfalt i zamieniają żwir w błoto, śnieg przykrywa jedno i drugie, pochmurno i mgła nic nie zmieniają. Rodzaj opon (`tire_kind` w części: `all_season`, `street`, `slick`, `rain`, `offroad`, `winter`) wybiera wiersz, a liczba z niego mnoży przyczepność, prowadzenie i hamowanie auta w tej pogodzie (mnożniki w ppm, liczone na liczbach całkowitych jak reszta).

| opony | asfalt suchy | asfalt mokry | żwir | błoto | śnieg |
|---|---|---|---|---|---|
| całoroczne | 0,95 | 0,90 | 0,65 | 0,50 | 0,60 |
| uliczne | 1,00 | 0,85 | 0,60 | 0,45 | 0,40 |
| slick | 1,15 | 0,45 | 0,55 | 0,30 | 0,20 |
| deszczowe | 0,90 | 1,05 | 0,55 | 0,50 | 0,55 |
| terenowe | 0,85 | 0,80 | 1,00 | 0,90 | 0,70 |
| zimowe | 0,80 | 0,90 | 0,75 | 0,65 | 1,00 |

To macierz „rozszerzona” z Systemów II. Opony uliczne dają dokładnie te liczby, które dawały płaskie mnożniki pogody sprzed macierzy (0,85 na mokrym, w ulewie 0,765 na przyczepności), więc okrążenia fabrycznych aut się nie zmieniły. Części opon w paczce: uliczne T1 i T2, całoroczne T1, terenowe T2, deszczowe T3, zimowe T3 i slick T7 (indeksy przyczepności z „Katalogu części” przeskalowane do liczb opon ulicznych z paczki: ×1,05; ceny z udziału opon w zestawie, Systemy V: 450, 570, 750 i 3000 $ dla tieru 1, 2, 3 i 7). Slick T7 nie jest w sklepie: wymaga auta poziomu 18.

**Szósta statystyka: Terenowość (klucz `clearance`).** Z projektu (Systemy I: „Prześwit i terenowość”). Nadwozie ma ją w definicji (`clearance`: Rdzak 20, Bizon 15, Kuna 80), a zawieszenie jest z nią uśredniane jak z prowadzeniem opon: sportowe obniżone (25) pomaga na asfalcie i odbiera Kunie przewagę, rajdowe (110) odwrotnie. Opony nie wchodzą do niej, bo działają przez macierz. Waży ją tylko tor żwirowy (35% w Kruszynie). Sufit paska w garażu to 200.

### Symulator torów (`tracks:simulate`)

```
php artisan tracks:simulate            # tabele i bramki
php artisan tracks:simulate --json     # raport jako JSON
php artisan tracks:simulate --strict   # kod 1 także, gdy jedno auto wygrywa więcej niż dwa tory (bramka M3)
```

Gra to, co gra: bierze każde auto paczki z każdą kombinacją części, które zmieniają okrążenie (opony, zawieszenie, hamulce, wydech, skrzynia), zamraża je tym samym kodem co bieg i szuka najlepszej na każdym torze. Zasady (moje, robocze):

- Zestaw jest dozwolony na torze, gdy mógł go zrobić kierowca poziomu toru: części nie wyższe niż tier, który montuje auto tego poziomu (nigdy niższego niż poziom nowego auta, czyli tier 2), a auto jest wrakiem startowym albo jego poziom u dealera nie przekracza toru.
- Najlepszy zestaw zarabia najwięcej na godzinę w klimacie regionu: każda pogoda liczy się z szansą, czasem okrążenia i nagrodą okrążenia. Opony zostają te same przez wszystkie pogody (cena braku drugiego kompletu, którego symulator nie modeluje). Gdy dwa zestawy jadą to samo, wygrywa tańszy (części, nie auto).
- Osobno próbuje wszystkich opon paczki na każdej nawierzchni (najtańszy wrak, nic więcej nie zmienione, bez limitu tieru): to tabela z projektu („deszcz wymusza zmianę opon”).

**Bramki.** Dwie kończą polecenie kodem 1, bo dotyczą spójności danych: (1) najlepsze opony na każdej nawierzchni to rodzaj z projektu (slick suchy asfalt, deszczowe mokry, terenowe żwir i błoto, zimowe śnieg), czyli deszcz zmienia zwycięzcę; (2) żadne auto, które wygrywa tor żwirowy, nie wygrywa toru asfaltowego, czyli teren wymusza inne auto. Trzecia to bramka M3 z roadmapy, „żaden build nie wygrywa więcej niż dwóch torów” (liczone na samochodach): kończy polecenie kodem 1 dopiero z `--strict`, bo zależy od tego, ile jest różnych aut.

**Co pokazał pierwszy przebieg** (reguły `2026.10.0`, paczka z trzema autami):

- Bramki spójności przechodzą. Na każdej nawierzchni wygrywa rodzaj z projektu: slick 51,4 s na suchym asfalcie (uliczne T2: 69,2 s), deszczowe 68,4 s na mokrym (78,2 s), terenowe 113,6 s na żwirze (165,6 s) i 125,2 s w błocie (209,3 s), zimowe 85,3 s na śniegu (180,9 s). Dobór opon to więc realna decyzja, a nie kosmetyka.
- **Bramka M3 przechodzi, ale cienko.** Bizon M-2 wygrywa dwa tory (Złomowo, Suchy Pas), Rdzak GT-4 dwa (Wilczą Przełęcz, Autodrom), Kuna 4x4 jeden (Kruszyn). Przewaga nad najlepszym innym autem to 1,4% na Złomowie (Rdzak), 1,5% w Kruszynie (znów Rdzak), 14,9% na Suchym Pasie (Rdzak), 5,8% na Wilczej Przełęczy (Bizon) i 2,1% na Autodromie (Bizon). Trzy tory rozstrzygają więc różnice rzędu 1 do 2 punktów procentowych, które każda zmiana liczb przestawi: to jest odpowiedź, że **różne auta na różne tory dają się wymusić, ale jeszcze niepewnie**, a pewniej będzie z napędem i szerszym katalogiem aut. Zanim doszła masa, ten sam przebieg pokazywał „jeszcze nie”: Bizon wygrywał cztery tory z pięciu, bo przyczepność, prowadzenie i hamowanie brały się z opon i zawieszenia, a nie z auta.

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
- Tytuł „tempo” obejmuje tylko drabinę aut. Planowane przez projekt, a **jeszcze niezrobione**: bramka „żaden build nie wygrywa więcej niż dwóch torów” (jest w `tracks:simulate`, opis niżej: dziś odpowiedź brzmi „jeszcze nie”), regulatory z audytem i trybem cienia, wskaźniki zdrowia z telemetrii, `reward_mult` osobno dla klas D–X, `price_index_used`, tempo rzadkich części (cele projektowe bez modelu), rynek, kursy i turnieje (siedzą w mnożniku aktywności), VIP.

## Wersje i zmiany balansu

Zasady wersjonowania definicji, cyklu życia (szkic, aktywna, wycofana) i osłabień opisuje dokument „Treść, konto i płatności” na płótnie projektowym. Schemat v0 obejmuje auta i części; miasta, tory i zestawy reguł dojdą w kolejnych wersjach schematu.
