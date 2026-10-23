# API biegu

Punkty końcowe pod `/api/v1/runs`. Bieg to auto jeżdżące okrążenia po torze; serwer rozlicza go leniwie (opis w `docs/settlement.md`), więc klient nie musi niczego odpytywać w tle.

## Zasady wspólne

- **Uwierzytelnienie:** token urządzenia (`Authorization: Bearer <token>`) z `POST /api/v1/players` albo z odzyskiwania konta. Bez tokenu odpowiedź to 401.
- **Język:** nagłówek `X-Locale` (pl, en, fr, de, pt-BR), potem język konta, potem `Accept-Language`. Dotyczy nazw auta i toru oraz komunikatów błędów. Kody błędów (`code`) są stałe i nie zależą od języka.
- **Jednostki:** pieniądze w centach, czas i paliwo w liczbach całkowitych (milisekundy od początku epoki Unix według zegara serwera, mililitry). Do odliczania klient używa `server_time_ms` z odpowiedzi, nie własnego zegara.
- **Bez pamięci podręcznej:** każda odpowiedź ma `Cache-Control: no-store`, bo jest prawdziwa tylko w chwili wywołania.
- **Jeden bieg na kierowcę** (na razie): drugie auto na torze podwajałoby dochód. Bieg innego kierowcy jest dla wywołującego „nieistniejący" (404 `no_run`), nie „zabroniony".
- **Odczyt rozlicza.** Jazda, którą wyznaczył upływ czasu, jest tylko zapisywana w momencie, gdy ktoś pyta. Drugie wywołanie w tej samej chwili nic nie zmienia, więc `GET` jest bezpieczne do ponawiania.

Każda odpowiedź ma `data` (bieg) i `wallet` (gotówka w centach i XP po wywołaniu). Reszta zależy od punktu końcowego.

```json
{
  "data": {
    "id": 1,
    "car": { "slug": "rdzak-gt-4", "name": "Rdzak GT-4" },
    "track": { "slug": "zlomowo-street", "name": "Sprint uliczny Złomowo" },
    "status": "running",
    "stop_reason": null,
    "laps_done": 30,
    "fuel_ml": 12000,
    "tank_ml": 45000,
    "cursor_ms": 1792692900000,
    "state_version": 3,
    "prices": { "fuel_cents_per_liter": 200, "repair_cents": 3000 },
    "lap": {
      "ms": { "dry": 75000, "rain": 84391 },
      "cash_cents": { "dry": 1550, "rain": 1442 },
      "xp": { "dry": 2, "rain": 2 },
      "fuel_ml": 1500
    },
    "weather": { "now": "rain", "ends_ms": 1792693350000, "next": "dry" },
    "current_lap": { "start_ms": 1792692900000, "finish_ms": 1792692984391, "ms": 84391, "weather": "rain" },
    "server_time_ms": 1792692960123
  },
  "wallet": { "cash_cents": 46500, "xp": 60 },
  "tow": { "available": false, "delay_ms": 300000 }
}
```

`tow` mówi, czy kierowca może teraz poprosić o holowanie (patrz niżej), liczone tą samą regułą co sama akcja. `status` to `running` albo `stopped`, a `stop_reason` to `fuel_empty`, `breakdown` albo `null`. `prices` to ceny akcji biegu w centach (litr paliwa i naprawa), żeby przycisk mógł podać koszt przed naciśnięciem. `cursor_ms` to początek bieżącego okrążenia. `weather` to pogoda regionu teraz (`now`), kiedy kończy się jej odcinek 22,5 minuty (`ends_ms`) i co przyniesie następny (`next`): klimat losuje się z ziarna, więc da się go powiedzieć naprzód. `current_lap` to okrążenie w toku: zaczęło się tam, gdzie rozliczenie zostawiło kursor, trwa tyle, ile okrążenie w pogodzie, w której się zaczęło, i kończy się w `finish_ms`; auto, które stoi (warsztat, pusty bak, awaria) albo czeka na holowanie, ma `null`. Ekran wyścigu liczy po tym odliczanie i pyta serwer o wynik okrążenia, gdy ono mija. `state_version` rośnie przy każdej zmianie stanu i jest tym, co klient odsyła jako `since_version`.

## Punkty końcowe

| Metoda i ścieżka | Co robi |
|---|---|
| `GET /starter-cars` | Wraki startowe do wyboru, z opisem i tym, co robią na torze startowym |
| `POST /runs` | Start: wrak startowy na torze, z pełnym bakiem |
| `GET /runs/current` | Bieg kierowcy, rozliczony do teraz, z podsumowaniem |
| `GET /runs/{id}` | To samo, po identyfikatorze |
| `POST /runs/{id}/refuel` | Tankowanie |
| `PATCH /runs/{id}` | Zmiana ustawień (na razie toru) |
| `POST /runs/{id}/repair` | Naprawa po awarii |
| `POST /runs/{id}/tow` | Holowanie dla kierowcy, którego nie stać na ruszenie stojącego auta |

### Wybór wraku: `GET /starter-cars`

Trzy wraki startowe, od najtańszego (to on jest polecany na start), i tor, na którym zacznie się bieg (`track`). Każde auto ma `name`, `description`, `class`, `drivetrain`, `engine` (`layout`, `displacement_l`), `power_kw`, `mass_kg`, `top_speed_kmh`, `cargo_kg`, `tank_l` oraz `lap`: czas okrążenia, nagrodę, XP i zużycie paliwa w najczęstszej pogodzie na torze. Liczby `lap` policzył ten sam kod, który zamraża bieg, więc ekran wyboru pokazuje to, co auto naprawdę pojedzie.

### Start: `POST /runs`

```json
{ "car": "rdzak-gt-4", "track": "zlomowo-street" }
```

`car` musi być wrakiem startowym z paczki treści (`sources` zawiera `start`), `track` torem z `content/rules/starter.json`, który otwiera poziom gracza (`min_player_level` toru; inaczej 422 `track_locked`, opis w części „Poziom gracza i tory”). Odpowiedź to 201. Kierowca, który ma już bieg, dostaje 409 z `code: run_exists` i `run_id`. Dwa równoległe żądania tego samego kierowcy nie założą dwóch biegów (blokada portfela, sprawdzone na osobnych procesach).

### Podsumowanie: `GET /runs/current` i `GET /runs/{id}`

Oprócz `data` i `wallet`:

- `settlements`: rozliczenia, które zrobiło to wywołanie (zwykle jedno albo żadne). Z `?since_version=N`, gdzie N to `state_version`, które klient widział ostatnio, są to wszystkie rozliczenia od tej wersji (najwyżej 100). Dzięki temu podsumowanie nieobecności nie ginie, gdy odpowiedź nie dotrze.
- `summary`: suma `settlements` (`laps`, `cash_cents`, `xp`), przedział czasu (`from_ms`, `to_ms`) i powód ostatniego postoju.

Każde rozliczenie ma `version`, `from_ms`, `to_ms`, `at_ms`, `laps`, `cash_cents`, `xp`, `stop_reason` i `events`: `lap_batch` (okrążenia w jednym odcinku pogody, z `weather`, `laps`, `cash_cents`, `xp`), `breakdown` i `fuel_empty`. Zdarzenie bez szczegółów ma `payload` równy `{}`. Brak biegu to 404 `no_run`.

### Tankowanie: `POST /runs/{id}/refuel`

```json
{ "request_id": "d3b07384-d9a1", "liters": 10 }
```

`request_id` (do 64 znaków) wybiera klient dla jednego zamiaru zatankowania. `liters` (całe litry) może zostać pominięte, wtedy auto jest tankowane do pełna; nigdy nie więcej, niż mieści bak. Odpowiedź ma `refuel`: `added_ml`, `cost_cents`, `repeated`. Cena paliwa jest w zasadach (2 $/l, zaokrąglenie w górę do centa). Najpierw bieg jest rozliczany, więc to, co auto zarobiło, może zapłacić za paliwo. Auto, które stanęło bez paliwa, rusza od teraz (czas postoju nie jest płatny), a auto po awarii zostaje zatrzymane do naprawy.

### Zmiana toru: `PATCH /runs/{id}`

```json
{ "track": "zlomowo-street" }
```

Rozlicza i wypłaca jazdę ze starym torem, potem zamraża nowy i auto jedzie nim od teraz. Okrążenie w toku przepada, żeby zmiana nie działała wstecz. Planowana awaria nie przesuwa się (ziarno biegu zostaje). Zatrzymane auto zostaje zatrzymane. Wysłanie tej samej zmiany w tej samej chwili nic nie płaci dwa razy. Tor musi być otwarty poziomem gracza, tak jak przy starcie (422 `track_locked`, nic się wtedy nie rozlicza i nie zmienia).

### Naprawa: `POST /runs/{id}/repair`

```json
{ "request_id": "9a8f3c10-77b2" }
```

Naprawia auto po awarii za koszt z zasad (30 $), po rozliczeniu, więc zarobek sprzed awarii może za nią zapłacić. Auto dostaje nowy plan następnej awarii (nowe ziarno) i rusza od teraz. Odpowiedź ma `repair`: `repaired`, `cost_cents`, `repeated`. Auto, które się nie zepsuło, zostaje bez zmian i `repaired` jest `false`, więc naprawą nie da się losować awarii od nowa. Jeśli bak jest też pusty, auto zostaje zatrzymane z powodu paliwa i rusza je dopiero tankowanie.

### Holowanie: `POST /runs/{id}/tow`

Bez treści. Wyjście dla kierowcy, którego nie stać na ruszenie stojącego auta: stojące auto nie zarabia, więc bez tego pieniądze nigdy by się nie pojawiły. Holowanie przysługuje, gdy auto stoi, a gotówka jest poniżej tego, co kosztuje zwykłe ruszenie: naprawa po awarii i paliwo na jedno okrążenie, gdy bak jest za pusty (paliwo w całych litrach, bo tyle da się kupić). Pieniądze zarobione przed awarią się liczą, bo bieg jest najpierw rozliczany.

Holowanie jest **darmowe, ale auto czeka** `tow.delay_ms` (5 minut), zanim znów pojedzie, i ten czas nie jest płatny: `cursor_ms` jest wtedy w przyszłości, a `status` to `running`, więc klient może odliczać do startu. Auto po awarii jest naprawiane tak jak w `repair` (nowe ziarno, nowy plan awarii), a auto z bakiem za pustym na okrążenie dostaje paliwo na dwa okrążenia, żeby zarobić na następne. Czas czekania kosztuje więcej utraconego zarobku (62 $) niż naprawa z paliwem (36 $), więc nie opłaca się go wybierać, gdy można zapłacić; pilnuje tego test reguł.

Odpowiedź ma `towed`: `false`, gdy auto jedzie albo kierowca jednak może zapłacić (nic się nie zmienia), więc drugie wywołanie holuje raz i nie wymaga `request_id`.

## Ponawianie i idempotencja

`request_id` chroni przed podwójnym obciążeniem przy ponowieniu żądania po zgubionej odpowiedzi: to samo żądanie ponowione zwraca `repeated: true` i niczego nie zmienia, także gdy w międzyczasie stan się zmienił (na przykład auto zepsuło się drugi raz). Nowy zamiar to nowy `request_id`. Kluczem w księdze jest `refuel:{bieg}:{request_id}` albo `repair:{bieg}:{request_id}`.

## Błędy

| Status | `code` | Kiedy |
|---|---|---|
| 401 | | Brak albo zły token |
| 404 | `no_run` | Kierowca nie ma biegu albo bieg nie jest jego |
| 409 | `run_exists` | Start, gdy kierowca ma już bieg (`run_id` w odpowiedzi) |
| 422 | `insufficient_funds` | Za mało gotówki na tankowanie lub naprawę (`available_cents`, `required_cents`). Nic się nie zmienia, nawet rozliczenie |
| 422 | `track_locked` | Start lub zmiana toru poniżej poziomu gracza, którego tor wymaga (`details.required`, `details.current`). Komunikat mówi, jak zdobyć poziom |
| 422 | `city_locked`, `city_not_found`, `no_road`, `already_there`, `already_travelling`, `car_cannot_travel`, `travel_no_fuel`, `car_travelling`, `track_elsewhere` | Podróż i miasta: patrz „Mapa i podróż”. `city_locked` ma `details` jak `track_locked`, `travel_no_fuel` ma w `details` litry (`required`, `available`) |
| 422 | | Błędne dane: nieznane auto lub tor, brak `request_id`, `liters` poniżej 1 lub nie całkowite, zła `since_version`. Szczegóły w `errors` |

Komunikaty (`message`) są w języku żądania.

## Skąd są dane

Auto pochodzi z paczki `content/packs/core`, a tor, klimat regionu, efekty pogody, cena paliwa, koszt naprawy i szansa awarii z `content/rules/starter.json` (opis w `content/README.md`). Statystyki auta wynikają z nadwozia i zamontowanych części (opis w części „Garaż, sklep i warsztat”), a auto bez części w gnieździe ma fabryczną, na oponach `stock_tires`. Wartości bez źródła w projekcie (koszt naprawy, szansa awarii) są tymczasowe.

## Garaż, sklep i warsztat

Kierowca ma auta i części jako wiersze (`cars`, `parts`), a bieg jeździ autem (`race_runs.car_id`). Części są w inwentarzu (`car_id` puste) albo zamontowane w gnieździe auta (`mounted_slot`; baza sama odrzuca drugą część w jednym gnieździe). Reguły są w `content/rules/starter.json` (`car`, `shop`, `workshop`), opis w `content/README.md`.

### Sklep: `GET /shop` i `POST /shop/purchases`

`GET /shop` zwraca części na sprzedaż (kolejność z reguł, nazwa w języku kierowcy, `price_cents`, `tier`, `stats` i, dla opon, `surfaces`: mnożnik przyczepności na każdej nawierzchni, `null` dla reszty części) i dla każdej `required_car_level` oraz `mountable`: czy auto kierowcy ma poziom na jej montaż (tier ≤ 1 + ⌊poziom auta / 3⌋). Do tego `data.car` (id i poziom albo `null`) i `wallet`.

`POST /shop/purchases` (`part`: slug, `request_id`: do 64 znaków) kupuje część do inwentarza: pieniądze (klucz księgi `shop:{kierowca}:{request_id}`) i część w jednej transakcji pod blokadą portfela. Odpowiedź 201 ma `data` (część), `purchase.cost_cents` i `wallet`. Powtórzone `request_id` nie pobiera ani nie daje drugi raz (200, `purchase.repeated: true`, `data: null`), a to samo `request_id` dla innej części to 409 `request_reused`. Błędy: 422 `part_not_for_sale` (nie ma jej na liście reguł, także gdy jest w paczce), 422 `insufficient_funds`.

### Garaż: `GET /garage`

Najpierw wykonuje to, co w warsztacie jest już należne. `data.car`: poziom, nadwozie, `stats` (przyspieszenie, prędkość maksymalna, przyczepność, prowadzenie, hamowanie) z częściami i `base_stats` bez nich, `ceilings` (wartość każdej z tych statystyk, przy której pasek w garażu jest pełny, z `car.stat_ceiling` reguł: to, co ma osiągać najlepsze auto całej gry, jednakowe na każdym torze), `tank_ml`, `lap_ms` biegu, stan biegu i dziewięć gniazd w stałej kolejności (opony, zawieszenie, hamulce, silnik, doładowanie, skrzynia, wydech, chłodzenie, zbiornik; silnik to na razie część nadwozia, `built_in`). `data.inventory`: części poza autem. `data.workshop`: `queue_max`, mechanik i niewykonane zlecenia. `now_ms` to czas serwera: czasy zleceń (`start_ms`, `finish_ms`) są w jego zegarze, więc odliczanie nie zależy od zegara klienta.

### Warsztat: `POST /garage/jobs`

`kind` (`mount` albo `unmount`) i `part` (id części kierowcy) ustawiają zlecenie w kolejce jednego mechanika. Czas zlecenia to czas bazowy gniazda dla tieru 1 razy tier, skrócony o 2% za poziom mechanika; zdjęcie części trwa 60% tego czasu. Zlecenie startuje, gdy skończy się poprzednie (albo od razu, gdy mechanik jest wolny), a czasy ustala się przy ustawianiu w kolejce. Montaż w gnieździe, które ma część, ustawia najpierw osobne zlecenie zdjęcia starej, więc wymiana to dwa miejsca w kolejce (mieści się cztery). Odpowiedź 201 to cały garaż. Błędy 422 z kodem: `no_car`, `part_not_found`, `part_busy` (część już czeka w kolejce), `slot_busy` (inna część tego gniazda już czeka na montaż), `part_mounted`, `part_not_mounted`, `car_level_required` (z `details.required` i `details.current`), `queue_full`.

**Nic nie działa w tle.** Zlecenia wykonują się leniwie, w kolejności swoich czasów, gdy kierowca wraca (każdy punkt końcowy biegu i `GET /garage` robią to najpierw, żeby bieg nigdy nie został rozliczony poza początek zlecenia):

- gdy zlecenie startuje, **auto wchodzi do warsztatu**: to, co zarobiło do tej chwili, jest rozliczone, a potem stoi (`status: stopped`, `stop_reason: workshop`) i nic nie zarabia, więc strojenie kosztuje czas poza ceną części;
- gdy zlecenie się kończy, część jest w aucie albo zdjęta;
- gdy kończy się ostatnie zlecenie z rzędu, konfiguracja biegu jest zamrażana od nowa z częściami, które auto ma teraz (to samo ziarno, więc zaplanowana awaria się nie przesuwa), a auto jedzie od tej chwili. Auto, które stało z innego powodu (awaria), zostaje stojące i dostaje tylko nową konfigurację.

Tankowanie nie rusza auta w warsztacie, a holowanie nie jest wtedy oferowane (`tow.available: false`).

Statystyki auta (`RunInputsFactory::stats`): moc nadwozia razy `power_mult` części, przyspieszenie to moc na tonę, prędkość maksymalna rośnie z pierwiastkiem sześciennym zysku mocy i nie przekracza limitu skrzyni, przyczepność, prowadzenie i hamowanie biorą się z opon (fabrycznych, gdy żadnych nie założono), a zawieszenie i hamulce są uśredniane z prowadzeniem i hamowaniem opon, **terenowość** (`clearance`) startuje od nadwozia, a zawieszenie jest z nią uśredniane tak samo. Prowadzenie i hamowanie mnoży na końcu **masa nadwozia**: `(1000 kg / masa) ^ 0,6` (`car.mass` w regułach, `Rules::massFactor`), więc ciężkie auto gorzej skręca i hamuje, a lekkie lepiej; Rdzak (1000 kg) zostaje bez zmian. **Uśrednianie to pierwsza reguła do sprawdzenia w symulatorze ekonomii.** To, jak opony trzymają na danej nawierzchni, nie jest w statystykach auta, tylko w okrążeniu: opisuje to część „Nawierzchnie, opony i tory” niżej. Sklep sprzedaje wszystko, co zmienia jazdę: opony (uliczne, całoroczne, terenowe, deszczowe, zimowe), zawieszenie (sportowe, rajdowe), hamulce i wydech.

### Anulowanie zlecenia: `DELETE /garage/jobs/{job}`

Anuluje zlecenie, które czeka na mechanika albo które mechanik właśnie robi. **Projekt o tym milczy** (plansza Warsztat nie ma przycisku anulowania), więc reguły poniżej to moja decyzja i są robocze:

- Anulowanie nic nie kosztuje i nic nie zwraca, bo zlecenie kosztuje tylko czas. Praca, którą mechanik już wykonał przy zleceniu w toku, przepada, a część zostaje tam, gdzie była: zdjęcie się nie odbywa, założenie też.
- Zlecenia wykonanego nie da się anulować (422 `job_done`): część jest już w nowym miejscu. To, czego czas minął od ostatniego pytania, wykonuje się przed sprawdzeniem, więc „wykonane” znaczy wykonane w chwili pytania. Zlecenia, którego nie ma albo które należy do kogoś innego, nie da się odróżnić od siebie (422 `job_not_found`), tak samo jak drugiego anulowania tego samego.
- Zdjęcie ciągnie za sobą założenie części, która miała zająć to gniazdo (wymiana to dwa zlecenia, a założenie bez zdjęcia nie miałoby się jak odbyć). Anulowanie samego założenia zostawia zdjęcie starej części, więc gniazdo zostanie puste. `cancelled` w odpowiedzi to id anulowanych zleceń, to, o które poproszono, pierwsze.
- Zlecenia za anulowanym przesuwają się do przodu: każde trwa tyle, co trwało, i startuje od końca zlecenia, które mechanik nadal robi, a gdy takiego nie ma, od teraz. Pierwsze z nich rusza od razu, więc auto nie wychodzi z warsztatu między zleceniami.
- Gdy po anulowaniu nie zostaje żadne zlecenie, a auto stało przez warsztat (`stop_reason: workshop`), jedzie dalej od teraz z konfiguracją z częściami, które ma; za czas postoju nic nie dostaje. Auto, które stało z innego powodu (awaria), zostaje stojące.
- Wiersze anulowanych zleceń są usuwane: miejsca w kolejce (cztery) wracają, a część można znów ustawić w kolejce.

Odpowiedź 200 to cały garaż, jak przy ustawianiu w kolejce, z dodatkowym `cancelled`. Błędy 422 z kodem: `job_not_found`, `job_done`.

### Mapa i podróż: `GET /map` i `POST /travel`

Prototyp mapy z projektu (plansza Mapa). **Miasto to region reguł** (`regions` w `rules/starter.json`: nazwa w pięciu językach, położenie na planszy 390 × 716, klimat), a droga łączy dwa miasta i ma długość w km (`roads`). Miasto ma tory (dziś po jednym), a auto stoi w mieście toru, na którym jedzie (`tracks[].region`), więc osobnego pola „miasto” w biegu nie ma. Nowy kierowca zaczyna w mieście toru startowego (Złomowo).

`GET /map` zwraca: `here` (miasto, w którym jest auto; bez biegu miasto startowe), `level` (poziom gracza), `cities` (slug, nazwa w języku kierowcy, `x`, `y`, `home`, `here`, `level_required`, `open`, `track` z nazwą, nawierzchnią, światłami, czasem bazowym i wagami statystyk, `fit_percent` i `route`), `roads` i `travel` (trwająca podróż albo `null`). **`fit_percent`** to dopasowanie auta do toru w suchym: czas bazowy toru przez czas okrążenia tego auta z jego częściami, w procentach (100 to auto, które pasuje dokładnie do wartości odniesienia toru; fabryczny Rdzak ma 100 na Złomowie i ok. 50 w kamieniołomie). **`route`** to droga z miasta, w którym jest auto: `km` (najkrótsza po drogach, Dijkstra w `RoadMap`), `ms` i `fuel_ml`; `null` dla miasta, w którym auto jest, dla kierowcy bez auta i w czasie podróży. Odpowiedź przed odczytem wykonuje to, co należne (przyjazd).

`POST /travel` (`to`: slug miasta) wysyła auto. Odpowiedź 201 to ta sama mapa. Podróż to zlecenie czasowe auta i **wykonuje się leniwie jak zlecenia warsztatu**: nic nie działa w tle, a przyjazd wykonuje się przy następnym pytaniu (`Workshop::advance` wywołuje teraz najpierw `Travel::advance`, więc robi to każdy punkt końcowy biegu i garażu).

- **Co kosztuje.** Auto jedzie ze średnią prędkością równą `travel.speed_share_ppm` (50%) swojej prędkości maksymalnej z częściami, więc mocniejsze auto dojeżdża szybciej (38 km do Kruszyna to 26 min dla Rdzaka), i pali `travel.fuel_ml_per_km` (60 ml) na kilometr razy zużycie paliwa auta. Liczby są robocze, projekt ich nie podaje (Kursy pokazują 35 do 41 minut za 38 do 61 km dla furgonu).
- **Wyjazd.** Auto zarabia do chwili wyjazdu (rozliczenie), potem stoi (`status: stopped`, `stop_reason: travel`) i nic nie zarabia, a paliwo drogi schodzi z baku. Okrążenie w toku przepada. Holowanie nie jest wtedy oferowane, a tankowanie nie rusza auta (jak w warsztacie): to „postój serwisowy” (`RaceRun::SERVICE_STOPS`), przy którym rozliczenie nie przesuwa kursora.
- **Przyjazd.** Bieg dostaje tor miasta docelowego (pierwszy tor regionu), zamrożony od nowa z częściami, które auto ma (to samo ziarno, więc zaplanowana awaria się nie przesuwa), i auto jedzie od chwili przyjazdu. W odpowiedzi biegu `travel` (z nazwami miast w języku kierowcy) jest, dopóki auto jest w drodze, a `track.region` mówi, w którym mieście stoi.
- **Bramka poziomu gracza.** Miasto otwiera najniższy `min_player_level` jego torów (3 Kruszyn, 5 Suchy Pas, 8 Wilcza Przełęcz, 12 Autodrom, z planszy). Zamknięte miasto to 422 `city_locked` z `details` (`required`, `current`) i komunikatem „Wymaga poziomu gracza 8 (masz 1). Zdobywasz XP, jeżdżąc.”.
- **Inne odmowy (422).** `no_run` (brak biegu), `city_not_found`, `already_there`, `already_travelling`, `no_road` (żadna droga tam nie prowadzi), `car_cannot_travel` (wyjechać może tylko auto, które jedzie: nie to po awarii, z pustym bakiem ani w warsztacie) i `travel_no_fuel` (`details.required` i `details.available` w litrach; paliwo nie wystarcza na drogę). Nic się wtedy nie zmienia.
- **Warsztat i tor.** Warsztat nie pracuje przy aucie w drodze (422 `car_travelling` przy ustawianiu zlecenia), a `PATCH /runs/{id}` zmienia tor tylko w obrębie miasta, w którym stoi auto (inaczej 422 `track_elsewhere`): do innego miasta jedzie się drogą.

Podróże są w tabeli `travels` (jedna trwająca na kierowcę) i wchodzą do kopii danych konta oraz do usunięcia konta. **Czego nie ma w prototypie:** wielu aut (jest jedno auto i jeden bieg, więc „zostaw auto w mieście A i graj drugim w B” dochodzi z garażami w miastach), garaży i warsztatów w miastach, odwołania podróży, drogi liczonej po odcinkach (pozycja auta na mapie to prosta między miastami, także gdy droga prowadzi przez inne miasto) i zdarzeń w drodze.

### Zegar gry, pory dnia i noc

Zegar gry (Systemy II, „Pogoda i pora dnia”) jest wspólny dla wszystkich i wynika wyłącznie z czasu serwera, więc nie ma żadnego zapisu, a rozliczenie liczy go wstecz, tak jak prognoza w przód: **trzy godziny realne to doba gry**, czyli godzina gry trwa 7,5 minuty, a minuta 7,5 sekundy. Doba zaczyna się co trzy godziny od początku epoki Unixa (`GameClock`, czysta klasa domeny, na liczbach całkowitych). Pory dnia: świt 5–8, dzień 8–18, zmierzch 18–22 i noc 22–5.

**Noc zmienia okrążenie tylko na torze bez świateł**: okrążenie, które zaczyna się w nocy, trwa o 3% dłużej (`clock.night_lap_ppm` w `rules/starter.json`, 1 030 000 ppm). Tor ma flagę `lit`: Złomowo i Autodrom mają światła (moja decyzja, projekt mówi tylko „na torach nieoświetlonych”), a kamieniołom, drag na Suchym Pasie i serpentyny nie. Nagroda i XP okrążenia się nie zmieniają, więc noc kosztuje okrążenia, nie wypłatę. Mgła, zmierzch i świt nie zmieniają okrążenia, bo projekt nie podaje liczb.

W rozliczeniu (`Settler`) przedział okrążeń jest cięty w chwilach, w których noc się zaczyna i kończy (22:00 i 5:00 gry), ale **tylko gdy konfiguracja ma noc** (`night_lap_ppm` ≠ 1 000 000), więc bieg na torze z światłami jest rozliczany dokładnie tak jak przedtem, a wektory testowe się nie zmieniły. Okrążenie zaczęte tuż przed nocą zachowuje czas dzienny. Test własności zostaje: rozliczenie w kawałkach daje to samo co jedno rozliczenie, także przez całą dobę gry z oboma brzegami nocy. Zamrożona konfiguracja ma `night_lap_ppm` (bieg zamrożony przed tą zmianą go nie ma i jest taki sam przez całą dobę).

Odpowiedź biegu (`GET /runs/current`, `GET /runs/{id}` i reszta) ma teraz:

- `clock`: `day_ms` (10 800 000) i `phases` (klucz i godzina gry, od której zaczyna się pora), z których klient liczy godzinę gry z zegara serwera, nic więcej nie pytając;
- `lap.night_ppm`: ile czasu okrążenia trwa nocne okrążenie (1 000 000 na torze ze światłami);
- `weather.forecast`: ten odcinek pogody (22,5 min) i cztery następne, czyli półtorej godziny naprzód, każdy z `weather`, `starts_ms`, `ends_ms` i `surface` (nawierzchnia, jaką pogoda robi z toru: kolumna macierzy opon). `weather.now`, `ends_ms` i `next` zostają jak były;
- `current_lap.ms`: okrążenie w toku ma już czas nocny, gdy zaczęło się w nocy.

Tabela `lap.ms` to czasy dzienne po pogodach, nocnych nie ma osobno.

### Nawierzchnie, opony i tory

Tor ma nawierzchnię (asfalt albo żwir), a pogoda ma grunt (sucho, mokro, śnieg): razem to jedna z pięciu nawierzchni, czyli kolumn **macierzy opon**. Rodzaj opon z definicji części (`tire_kind`) wybiera jej wiersz, a liczba mnoży przyczepność, prowadzenie i hamowanie auta w okrążeniu tej pogody, więc **zamrożona konfiguracja biegu zależy od zamontowanych opon**: `lap_ms`, `lap_cash_cents` i `lap_xp` mają wpis na każdą pogodę regionu (w górach także `snow`), policzony dla opon, które auto ma teraz. Zmiana opon w warsztacie zamraża bieg od nowa jak każda inna część. Opony uliczne dają te same liczby, co płaskie mnożniki pogody sprzed macierzy, więc okrążenia fabrycznych aut się nie zmieniły. Macierz, pięć torów (poziomy 1, 3, 5, 8 i 12), klimaty i moje decyzje z tym związane są w `content/README.md` (część „Tory, nawierzchnie i macierz opon”), a sprawdza je polecenie `php artisan tracks:simulate`.

### Poziom gracza i tory

Serwer liczy poziom gracza z XP z portfela: XP do poziomu *n* to `player.xp_base · n ^ player.xp_exponent` (45 i 2,3, do pełnych: poziom 2 od 222 XP), ta sama krzywa co w pasku HUD i w symulatorze ekonomii (test pilnuje, żeby się nie rozjechały), najwyżej `player.max_level` (30). Tor otwiera `min_player_level`. Start biegu i zmiana toru na tor, którego poziom gracza nie otwiera, to 422 `track_locked` z `details` (`required`, `current`) i komunikatem „Wymaga poziomu gracza 8 (masz 1). Zdobywasz XP, jeżdżąc.”: zasada projektu, że każda blokada pokazuje powód i ścieżkę. To jedyna bramka po stronie serwera; miasta i mapa dojdą z podróżami.

### XP i poziom auta

Auto awansuje na okrążeniach, które przejechało (projekt: „Okrążeń tym autem”). Każde rozliczone okrążenie daje autu **10 XP** (`car.xp_per_lap`), niezależnie od pogody, a XP dopisuje to samo rozliczenie, które liczy okrążenia, więc okrążenie liczy się raz i nigdy przed końcem. Pasmo od poziomu *n* do *n+1* to `200 · n` XP (`car.xp_per_level`), więc XP do poziomu *n* to `100 · n · (n − 1)`: trzeci poziom zaczyna się od 600 XP, a pasmo trzeciego poziomu ma 600, jak „410 / 600” na planszy Garaż. Nowe auto ma poziom 3 (`car.start_level`) i XP, od którego ten poziom się zaczyna. Poziom nie spada i kończy się na `car.max_level` (30, czyli tyle, ile trzeba na tier 10, który odblokowuje poziom 27). Liczby są wstępne: projekt podaje tylko, że auto awansuje na okrążeniach, a tempo ma sprawdzić symulator.

**Poziom auta ogranicza tier części**, którą auto może mieć zamontowaną: tier ≤ 1 + ⌊poziom / 3⌋ (reguła, która już była). Odmowa `car_level_required` mówi, ile trzeba i ile jest, oraz jak zdobyć poziom („Wymaga poziomu auta 6 (masz 4). Zdobywasz XP, jeżdżąc tym autem.”, zasada UX z projektu). XP auta nie dają ani statystyk („mały stały bonus” z projektu jest do zrobienia: zmieniłby zamrożoną konfigurację biegu przy każdym awansie), ani miast.

`GET /garage`: auto ma teraz `xp` (`total`, `level_start`, `next_level_at`, ostatnie `null` na najwyższym poziomie: pasek to `total` między początkiem a końcem poziomu) i `tier_unlock` (`tier` i `level`: najbliższy tier części, którego auto jeszcze nie zamontuje, i poziom, który go otwiera; `null`, gdy żaden poziom auta już go nie otwiera). Bieg (`lap`) mówi, co okrążenie daje autu: `car_xp`. Auta, które istniały przed tą zmianą, dostały XP, od którego zaczyna się ich poziom (migracja `add_xp_to_cars_table`): okrążenia przejechane wcześniej nie liczą się, bo nic ich dla auta nie zapisało. XP auta jest w kopii danych konta.

## Samouczek

Samouczek prowadzi pierwsze minuty gry SMS-ami od Kuby (mentora, który się ukrywa i pisze za każdym razem z innego numeru). **Kroki są danymi**: `content/rules/tutorial.json` (schemat `tutorial.schema.json`, teksty w pięciu językach, test `TutorialStepsTest`), więc kolejność, słowa i nagrody zmienia się bez wdrażania kodu. Serwer jest źródłem prawdy, a postęp kierowcy to wiersze tabeli `tutorial_progress` (jeden na krok, który kierowca osiągnął: `active`, `done` albo `skipped`, z czasami; krok bez wiersza nie jest jeszcze osiągnięty).

Każdy krok ma `trigger` (co go rozpoczyna, gdy poprzedni jest skończony) i `completion` (co go kończy). To fakty o grze, które serwer odczytuje ze stanu, a nie zdarzenia, na które nasłuchuje: `none`, `run_started` (jest bieg), `lap_completed` (ukończone okrążenie), `fuel_low` (w baku poniżej jednej czwartej), `fuel_restored` (co najmniej cztery piąte), `breakdown` (auto stoi po awarii), `recovered` (już nie stoi po awarii), `acknowledged` (kierowca odpowiedział na SMS). Dzięki temu samouczek, tak jak rozliczenie biegu, jest doprowadzany do stanu bieżącego, gdy ktoś na niego patrzy, i niczego nie traci z tego, co się stało pod nieobecność kierowcy.

| Krok | Rozpoczyna | Kończy | Podświetla | Nagroda |
|---|---|---|---|---|
| 1 `wreck` | od razu | wybór wraku (jest bieg) | nic (bez SMS-a) | brak |
| 2 `first-lap` | jest bieg | pierwsze okrążenie | okrążenia | 200 $ |
| 3 `rewards` | pierwsze okrążenie | odpowiedź kierowcy | gotówka w HUD | 100 XP auta |
| 4 `refuel` | bak poniżej 25% | bak od 80% | przycisk tankowania | 90 $ (tankowanie na koszt Kuby) |
| 5 `repair` | awaria | auto znów jedzie | przycisk naprawy | 30 $ (naprawa na koszt Kuby) |

Kroki 1–5 to pierwsze pięć z planszy Samouczek, dopasowane do tego, co w grze jest. Jedno odstępstwo: krok 5 w projekcie to naprawa hamulców i „stan techniczny” części, którego nie ma, więc tu jest awaria i naprawa, a ten krok czeka na pierwszą awarię (szansa 0,3% na okrążenie, więc może długo). Kroki 6–20 (skrzynki, sloty, mapa, miasta…) dojdą z mechanikami, których dotyczą. Nagrody to gotówka z kluczem idempotencji `tutorial:{kierowca}:{krok}`, księgowana w tej samej transakcji, która oznacza krok jako zrobiony, więc nie da się ich wypłacić dwa razy. Nic w samouczku nie kosztuje kierowcy gotówki: tankowanie i naprawa są mu zwracane.

### `GET /tutorial`, `POST /tutorial/reply`, `POST /tutorial/skip`

`GET` najpierw wykonuje to, co w warsztacie należne, i rozlicza bieg, a potem przesuwa kierowcę po krokach. Krok z SMS-ami jest najpierw **pokazany**: przejście do niego kończy przebieg, a ukończyć go można dopiero przy następnym spojrzeniu, więc kierowca zawsze zobaczy wiadomość, zanim krok się skończy. Krok bez SMS-ów (pierwszy) jest wykonywany bez pokazywania. Odpowiedź to `data`:

- `status`: `in_progress`, `finished` albo `skipped`;
- `total` i `done`: liczba kroków i skończonych;
- `current`: krok, który czeka, z `key`, `number` (od 1), `sender` (numer, z którego pisze Kuba), `sms` (jedna do dwóch wiadomości w języku kierowcy), `reply` (napis przycisku odpowiedzi), `target` (`laps`, `cash`, `refuel`, `repair`: co ekran podświetla) i `acknowledged` (kierowca już odpowiedział), albo `null`;
- `rewarded`: co wypłaciły kroki skończone tym wywołaniem (`key`, `cash_cents` i `car_xp`, oba zawsze są), żeby klient mógł to powiedzieć i odświeżyć gotówkę i garaż. XP auta trafia do auta kierowcy w tej samej transakcji, która kończy krok; kierowca bez auta dostaje 0.

`POST /tutorial/reply` odpowiada na SMS-y kroku, który czeka (telefon znika, a krok kończący się odpowiedzią jest skończony); dwa razy to jedna odpowiedź. `POST /tutorial/skip` zostawia bez wykonania kroki, które nie są skończone, **razem z ich nagrodami**; to, co zrobione i wypłacone, zostaje. Po `skip` samouczek już nie wraca. Obie odpowiedzi mają ten sam kształt co `GET`, a żadna nie jest buforowana. Postęp wchodzi do kopii danych konta (`game.tutorial`) i do usunięcia konta (rodzaj `tutorial steps`).

## Znane ograniczenia

- Jeden bieg na kierowcę, więc jedno auto na raz. Torów jest pięć, mapa i podróż między miastami są (prototyp, opis wyżej), ale garaży i warsztatów w miastach jeszcze nie ma, a ekran garażu nie ma wyboru toru: do innego miasta jedzie się z mapy.
- Wyjście z sytuacji bez wyjścia (kierowca, którego nie stać na ruszenie stojącego auta) to holowanie. Wartości (5 minut czekania, paliwo na dwa okrążenia) są tymczasowe i czekają na kalibrację razem z resztą reguł. Gdy pojawi się sklep, trzeba sprawdzić, czy czas czekania nadal kosztuje więcej niż to, co holowanie oszczędza.
- `ruleset_version` zapisany w biegu pochodzi z konfiguracji (`RIDE_RULESET_VERSION`), a nie z wersji pliku zasad.
