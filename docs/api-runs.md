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

### Wysłanie auta: `POST /runs/{id}/start`

**Pierwsze auto kierowcy czeka w garażu na Start** (uwaga testera: samouczek mówił tylko, że „auto jedzie samo”, a gracz nie wiedział, skąd i po co). `POST /runs` zakłada bieg **zaparkowany**: `status: stopped`, `stop_reason: parked` (przystanek serwisowy jak warsztat i podróż: nic się nie rozlicza, kursor stoi, holowanie nie jest oferowane, tankowanie i naprawa nie ruszają auta). `POST /runs/{id}/start` wysyła auto: jedzie od tej chwili (za czas postoju nic nie jest zapłacone ani należne), a odpowiedź to bieg jak zawsze. Auto rusza z **rozgrzewką** (`start.head_start_ms`, 60 s, nigdy więcej niż najkrótsze okrążenie minus sekunda): kursor biegu jest cofnięty o tyle, więc pierwsze okrążenie (75 s) kończy się 15 s po Starcie i od razu spada nagroda z samouczka. Kosztuje to ok. 12 $ jednorazowo (czas, którego auto nie jechało); kolejne okrążenia trwają tyle, co zawsze. Auto, które nie czeka (jedzie albo stoi z innego powodu), zostaje, jak było, więc powtórzony Start jest nieszkodliwy; cudzy albo nieistniejący bieg to 404 `no_run`. **Tylko pierwsze auto jest zaparkowane**: auta kupione później (wrak, giełda, dealer) jeżdżą od razu, bo „zaparkowane auta jeżdżą same” to założenie floty. Zaparkowane auto nie jedzie w trasę (`car_cannot_travel`), a zlecenie warsztatu nie robi z niego jadącego (po zleceniu nadal czeka na Start).

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

`GET /shop` zwraca części na sprzedaż (kolejność z reguł, nazwa w języku kierowcy, `price_cents`, `tier`, `stats` i, dla opon, `surfaces`: mnożnik przyczepności na każdej nawierzchni, `null` dla reszty części) i dla każdej `required_car_level` oraz `mountable`: czy auto kierowcy ma poziom na jej montaż (tier ≤ 1 + ⌊poziom auta / 3⌋). Do tego `data.car` (id i poziom albo `null`), **`data.crates`** (skrzynki, część „Skrzynki z częściami i rzadkość” niżej) i `wallet`.

`POST /shop/purchases` (`part`: slug, `request_id`: do 64 znaków) kupuje część do inwentarza: pieniądze (klucz księgi `shop:{kierowca}:{request_id}`) i część w jednej transakcji pod blokadą portfela. Odpowiedź 201 ma `data` (część), `purchase.cost_cents` i `wallet`. Powtórzone `request_id` nie pobiera ani nie daje drugi raz (200, `purchase.repeated: true`, `data: null`), a to samo `request_id` dla innej części to 409 `request_reused`. Błędy: 422 `part_not_for_sale` (nie ma jej na liście reguł, także gdy jest w paczce), 422 `insufficient_funds`.

### Garaż: `GET /garage`

Najpierw wykonuje to, co w warsztacie jest już należne. `data.car`: poziom, nadwozie, `stats` (przyspieszenie, prędkość maksymalna, przyczepność, prowadzenie, hamowanie) z częściami i `base_stats` bez nich, `ceilings` (wartość każdej z tych statystyk, przy której pasek w garażu jest pełny, z `car.stat_ceiling` reguł: to, co ma osiągać najlepsze auto całej gry, jednakowe na każdym torze), `tank_ml`, `lap_ms` biegu, stan biegu i dziewięć gniazd w stałej kolejności (opony, zawieszenie, hamulce, silnik, doładowanie, skrzynia, wydech, chłodzenie, zbiornik; silnik to na razie część nadwozia, `built_in`). `data.inventory`: części poza autem. `data.workshop`: `city` (slug i nazwa miasta, w którym stoi auto: warsztat jest miasta), `queue_max`, mechanik i niewykonane zlecenia tego miasta. `now_ms` to czas serwera: czasy zleceń (`start_ms`, `finish_ms`) są w jego zegarze, więc odliczanie nie zależy od zegara klienta.

### Warsztat: `POST /garage/jobs`

`kind` (`mount` albo `unmount`) i `part` (id części kierowcy) ustawiają zlecenie w kolejce mechanika miasta, w którym stoi auto, którym się gra (każde miasto ma własną kolejkę, patrz „Wiele aut…” niżej). Czas zlecenia to czas bazowy gniazda dla tieru 1 razy tier, skrócony o 2% za poziom mechanika; zdjęcie części trwa 60% tego czasu. Zlecenie startuje, gdy skończy się poprzednie (albo od razu, gdy mechanik jest wolny), a czasy ustala się przy ustawianiu w kolejce. Montaż w gnieździe, które ma część, ustawia najpierw osobne zlecenie zdjęcia starej, więc wymiana to dwa miejsca w kolejce (mieści się cztery). Odpowiedź 201 to cały garaż. Błędy 422 z kodem: `no_car`, `part_not_found`, `part_busy` (część już czeka w kolejce), `slot_busy` (inna część tego gniazda już czeka na montaż), `part_mounted`, `part_not_mounted`, `car_level_required` (z `details.required` i `details.current`), `queue_full`.

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

`GET /map` zwraca: `here` (miasto, w którym jest auto, którym się gra; bez biegu miasto startowe), `level` (poziom gracza), `cities` (slug, nazwa w języku kierowcy, `x`, `y`, `home`, `here`, `level_required`, `open`, `garage` (garaż kierowcy w tym mieście, opis w części o garażach), `track` z nazwą, nawierzchnią, światłami, czasem bazowym i wagami statystyk, `fit_percent` i `route`), `roads` i `travel` (trwająca podróż albo `null`). **`fit_percent`** to dopasowanie auta do toru w suchym: czas bazowy toru przez czas okrążenia tego auta z jego częściami, w procentach (100 to auto, które pasuje dokładnie do wartości odniesienia toru; fabryczny Rdzak ma 100 na Złomowie i ok. 50 w kamieniołomie). **`route`** to droga z miasta, w którym jest auto: `km` (najkrótsza po drogach, Dijkstra w `RoadMap`), `ms` i `fuel_ml`; `null` dla miasta, w którym auto jest, dla kierowcy bez auta i w czasie podróży. Odpowiedź przed odczytem wykonuje to, co należne (przyjazd).

`POST /travel` (`to`: slug miasta, `car`: id auta, domyślnie to, którym się gra) wysyła auto. Odpowiedź 201 to ta sama mapa. Podróż to zlecenie czasowe auta i **wykonuje się leniwie jak zlecenia warsztatu**: nic nie działa w tle, a przyjazd wykonuje się przy następnym pytaniu (`Workshop::advance` wywołuje teraz najpierw `Travel::advance`, więc robi to każdy punkt końcowy biegu i garażu).

- **Co kosztuje.** Auto jedzie ze średnią prędkością równą `travel.speed_share_ppm` (50%) swojej prędkości maksymalnej z częściami, więc mocniejsze auto dojeżdża szybciej (38 km do Kruszyna to 26 min dla Rdzaka), i pali `travel.fuel_ml_per_km` (60 ml) na kilometr razy zużycie paliwa auta. Liczby są robocze, projekt ich nie podaje (Kursy pokazują 35 do 41 minut za 38 do 61 km dla furgonu).
- **Wyjazd.** Auto zarabia do chwili wyjazdu (rozliczenie), potem stoi (`status: stopped`, `stop_reason: travel`) i nic nie zarabia, a paliwo drogi schodzi z baku. Okrążenie w toku przepada. Holowanie nie jest wtedy oferowane, a tankowanie nie rusza auta (jak w warsztacie): to „postój serwisowy” (`RaceRun::SERVICE_STOPS`), przy którym rozliczenie nie przesuwa kursora.
- **Przyjazd.** Bieg dostaje tor miasta docelowego (pierwszy tor regionu), zamrożony od nowa z częściami, które auto ma (to samo ziarno, więc zaplanowana awaria się nie przesuwa), i auto jedzie od chwili przyjazdu. W odpowiedzi biegu `travel` (z nazwami miast w języku kierowcy) jest, dopóki auto jest w drodze, a `track.region` mówi, w którym mieście stoi.
- **Bramka poziomu gracza.** Miasto otwiera najniższy `min_player_level` jego torów (3 Kruszyn, 5 Suchy Pas, 8 Wilcza Przełęcz, 12 Autodrom, z planszy). Zamknięte miasto to 422 `city_locked` z `details` (`required`, `current`) i komunikatem „Wymaga poziomu gracza 8 (masz 1). Zdobywasz XP, jeżdżąc.”.
- **Inne odmowy (422).** `no_run` (brak biegu), `city_not_found`, `already_there`, `already_travelling`, `no_road` (żadna droga tam nie prowadzi), `car_not_found` (podane `car` nie jest autem kierowcy), `no_garage` i `garage_full` (w mieście docelowym kierowca musi mieć garaż z wolnym miejscem), `car_cannot_travel` (wyjechać może tylko auto, które jedzie: nie to po awarii, z pustym bakiem ani w warsztacie, ani takie, któremu warsztat ma jeszcze coś do zrobienia) i `travel_no_fuel` (`details.required` i `details.available` w litrach; paliwo nie wystarcza na drogę). Nic się wtedy nie zmienia.
- **Warsztat i tor.** Warsztat nie pracuje przy aucie w drodze (422 `car_travelling` przy ustawianiu zlecenia), a `PATCH /runs/{id}` zmienia tor tylko w obrębie miasta, w którym stoi auto (inaczej 422 `track_elsewhere`): do innego miasta jedzie się drogą.

Podróże są w tabeli `travels` (jedna trwająca na auto) i wchodzą do kopii danych konta oraz do usunięcia konta. **Czego nie ma w prototypie:** odwołania podróży, drogi liczonej po odcinkach (pozycja auta na mapie to prosta między miastami, także gdy droga prowadzi przez inne miasto) i zdarzeń w drodze. Wiele aut, garaże i warsztaty w miastach opisuje następna część.

### Wiele aut, garaże i warsztaty w miastach: `GET /cars`, `POST /cars`, `POST /cars/{car}/select`, `POST /garages`

Krok 4 z F2. **Kierowca nie ma własnej pozycji: pozycję ma każde auto** (miasto toru jego biegu, tak samo jak przy podróży), a kierowca gra jednym autem naraz, **wybranym** (`users.selected_car_id`; gdy nic nie wybrano albo wybrane auto zniknęło, jest to pierwsze auto kierowcy). Każde auto ma własny bieg (`race_runs.car_id`) i **jeździ samo** na torze swojego miasta, więc „zostaw auto w A i graj drugim w B” działa: bieg wybranego auta pokazują `GET /runs/current`, `GET /garage`, warsztat, ekran Turniej i samouczek, a pozostałe auta jeżdżą i zarabiają na tym samym portfelu. Rozlicza je się leniwie, jak każdy bieg: przy `GET /cars`, zmianie auta i zakupie oraz przy ich własnych punktach końcowych `/runs/{id}`. Okno offline jest takie jak zawsze: auto jedzie do końca paliwa albo do awarii.

- **`GET /cars`** wykonuje to, co należne (warsztat, przyjazdy), rozlicza bieg każdego auta i zwraca `data.selected` (id), `data.cars` (id, `level`, `selected`, `vehicle`, `city`, `run` z `status`, `stop_reason`, `laps_done`, `fuel_ml`, `tank_ml` i nazwą toru, oraz `travel` z `to`, `to_name` i `arrive_ms` albo `null`, a od sprzedaży aut `sale_cents`, czyli co auto dziś przyniesie, i `sell_blocked`: `last_car`, `busy` albo `null`), `data.garages` (każde miasto z reguł: `owned`, `capacity`, `used`, `free`, `price_cents`, który jest `null`, gdy garaż już jest, oraz `level_required` i `open`), `data.yard` (wraki na sprzedaż od najtańszego: slug, nazwa, `class`, `price_cents`), `wallet` i `now_ms`. Odpowiedzi zmian (poniżej) mają ten sam kształt.
- **Garaże.** Garaż w mieście mieści `garage.capacity` aut (3). Garaż miasta startowego jest od początku (powstaje przy pierwszym pytaniu i nic nie kosztuje), inne kupuje się przez `POST /garages` (`city`) za `garage.price_cents` (5 000 $), ale tylko w mieście, które otwiera poziom gracza. Cena schodzi z portfela w tej samej transakcji, w której powstaje garaż (pod blokadą portfela, klucz księgi `garage:{kierowca}:{miasto}`), więc drugiego tego samego garażu nie da się kupić. Błędy 422: `city_not_found`, `garage_exists`, `city_locked` (`details.required` i `details.current`), `insufficient_funds`.
- **Miejsce w garażu** zajmuje auto, które stoi w mieście, a **auto w drodze trzyma miejsce w mieście docelowym** (`CityGarages::usedIn`). Dlatego auto nie pojedzie do miasta bez garażu kierowcy (422 `no_garage`) ani do garażu bez wolnego miejsca (422 `garage_full`); w obu komunikatach jest nazwa miasta (`details.city`). Do miasta z garażem, ale bez miejsca, auto trzeba najpierw zrobić miejsce (inne auto jedzie dalej) albo kupić garaż w innym.
- **Zakup wraku: `POST /cars`** (`vehicle`: slug wraku ze sprzedaży, `city`: miasto z garażem kierowcy i wolnym miejscem, `request_id`: do 64 znaków). Cena z `garage.wreck_price_cents` schodzi z portfela, a w garażu miasta staje auto (poziom 3, jak każde nowe) z własnym biegiem: pełny bak, pierwszy tor miasta, zamrożona konfiguracja bez części. Wszystko w jednej transakcji pod blokadą portfela, klucz księgi `wreck:{kierowca}:{request_id}`. Odpowiedź 201 ma `bought` (id auta) i `repeated: false`; **powtórzone `request_id` nie kupuje drugi raz** (200, `repeated: true`, `bought: null`). Kupione auto nie staje się granym: kierowca wybiera je sam. Błędy 422: `wreck_not_for_sale` (nie ma go w `garage.wreck_price_cents`, także gdy jest w paczce), `city_not_found`, `no_garage`, `garage_full`, `insufficient_funds`.
- **Wybór auta: `POST /cars/{car}/select`** ustawia auto, którym się gra, wykonuje to, co należne, i zwraca listę. Cudze albo nieistniejące auto to 422 `car_not_found` (te dwa przypadki są nie do odróżnienia).
- **Warsztat miasta.** Każde miasto ma **własny warsztat z własną kolejką** (`workshop_jobs.city` ustala się przy ustawianiu zlecenia z miasta auta) i własnego mechanika, który robi zlecenia aut kierowcy w tym mieście **jedno po drugim**, więc pełna kolejka (cztery) w jednym mieście nie blokuje zlecenia w innym. Zlecenie drugiego auta w tym samym mieście czeka, aż mechanik skończy zlecenie pierwszego (`start_ms` równy `finish_ms` poprzedniego), a samo auto jedzie, dopóki mechanik nie zacznie przy nim pracować: stoi tylko to auto, przy którym mechanik pracuje (`stop_reason: workshop`). `GET /garage` i `POST /garage/jobs` dotyczą auta granego i warsztatu jego miasta (`data.workshop.city`, a `jobs` to kolejka tego miasta).
- **Podróż autem.** `POST /travel` ma opcjonalne `car`; auto, któremu warsztat ma jeszcze zlecenie do zrobienia, nie wyjedzie (422 `car_cannot_travel`). Podróże trwają równolegle, po jednej na auto (`already_travelling` dotyczy auta, nie kierowcy), a `GET /map` liczy `here`, `route` i dopasowanie dla auta granego.
- **Dane konta.** Garaże są w tabeli `city_garages` (`user_id`, `region`, `capacity`) i wchodzą do kopii danych konta (`game.garages`, a w `account` jest `selected_car_id`) oraz do usunięcia konta.

**Liczby są robocze** (projekt ich nie rozstrzyga i wszystkie są w `rules/starter.json`, część `garage`): pojemność 3, cena garażu 5 000 $, wraki 1 140 $ (Rdzak GT-4), 1 390 $ (Bizon M-2) i 1 510 $ (Kuna 4x4). **Plansza Flota** ma inny model (aktywne sloty z limitem 2 i magazyn na 3 auta, trzeci slot za 600 diamentów albo VIP, zamiana trwa 2 minuty i kosztuje drobną opłatę) i **go nie bierzemy**: w prototypie jeżdżą wszystkie auta, a ograniczeniem jest miejsce w garażach. **Ryzyko do bilansu:** dochód rośnie z liczbą aut, a tanie wraki są niedrogie, więc bez limitu aut aktywnych i bez ceny zdobycia lepszych aut (dealer, giełda: krok 5) flota tanich wraków zarabia więcej, niż przewiduje drabina ekonomii. Symulator ekonomii nie modeluje floty (ma tylko mnożnik), więc to trzeba sprawdzić, zanim liczby się utrwalą. Limit aut aktywnych dojdzie z VIP-em i diamentami (F6).

### Rynek miasta: giełda, dealer i stan auta: `GET /market`, `POST /market/exchange/{key}/inspect`, `POST /market/exchange/{key}/buy`, `POST /market/dealer`

Krok 5 z F2 (Systemy III, punkt 11). **Rynek jest rynkiem miasta, w którym stoi auto, którym się gra** (kierowca nie ma własnej pozycji), a kupione auto staje w garażu tego miasta, więc potrzebny jest tam garaż z wolnym miejscem (422 `no_garage`, `garage_full`, jak przy wraku). Są trzy źródła aut: giełda (używane), dealer (nowe) i złomowisko (wraki: `POST /cars` z kroku 4, w odpowiedzi rynku jako `data.wrecks`, bo to najtańsze ogłoszenia).

- **`GET /market`** zwraca `data` z `city`, `level` (poziom gracza), `garage` (`owned`, `capacity`, `used`, `free` w mieście rynku), `exchange` (`refresh_ms`, `next_refresh_ms`, `listings`), `dealer`, `wrecks` i `storage` (magazyny, niżej), oraz `wallet` i `now_ms`. Odpowiedzi zmian (niżej) mają ten sam kształt, a kupno dodaje `bought` (id auta) i `repeated`. Przed odpowiedzią wykonuje się to, co należne (przyjazd), żeby miasto było miastem auta.
- **Giełda.** Kilka ogłoszeń (`market.exchange.listings`, 4), odświeżanych co `refresh_ms` (30 minut: numer odświeżenia to `floor(czas / refresh_ms)`, więc zaczyna się o pełnych trzydziestu minutach zegara serwera). **Niczego się nie zapisuje**: zestaw powstaje od nowa, ten sam, z ziarna kierowcy, miasta i numeru odświeżenia (`Rng`, sha256), więc każdy kierowca ma własną giełdę (nie ma o co się ścigać), a zapisane jest tylko to, co kierowca zrobił (wpis księgi na inspekcję i na kupno). Na giełdzie są auta paczki ze źródłem `exchange`, spalinowe i z `min_player_level` nie wyższym niż poziom gracza („na początku tylko tanie klasy”: na poziomie 1 jest tylko Rdzak GT-2). Ogłoszenie ma: przebieg (40 000 do 280 000 km, do tysiąca), wiek (z przebiegu i 8 000 do 18 000 km rocznie, od 3 do 25 lat), liczbę **usterek** zależną od przebiegu (wagi pasm w `market.exchange.defects`; waga zera to szansa na idealny stan: 15% do 100 tys. km, 8% do 180 tys., 3% powyżej), **stan** (każda usterka zabiera 15 do 25 punktów procentowych, bez usterek jest 100%, nie mniej niż 5%), **słowo** (`good`, `fair`, `poor`: stan z błędem ±15 pp i progi 75% i 50%, więc tylko z grubsza zgodne z prawdą) i **cenę**.
- **Cena** to wzór z Systemy V, punkt 16: `cena nowego × max(dno klasy, 0,89^wiek) × czynnik przebiegu × (0,35 + 0,65 × stan)`, gdzie czynnik przebiegu to `max(0,7; 1 − max(0, km − 15 000 × wiek) / 300 000)`; w całych dolarach, nie mniej niż 1 $ (`UsedCarPricing`, same liczby całkowite w ppm). Wzór odtwarza ogłoszenia z planszy giełdy (Rdzak GT-3 $3 620, Kornet V6 $8 140, Furgon Z-1 $3 440) i ceny wraków z Systemy V (Rdzak GT-4 $1 140, Bizon M-2 $1 390, Kuna 4x4 $1 510) w granicach 1 do 2%, a testy to pilnują. Dna klas to `market.pricing.class_floor_ppm` (przypisanie klas aut do klas cenowych jest moje: hatchback, van, pickup i muscle 6%, sedan 8%, coupe i hot hatch 20%, GT 45%, super 80%, hyper 90%).
- **Inspekcja: `POST /market/exchange/{key}/inspect`** kosztuje `market.exchange.inspection_ppm` (3%) ceny, w całych dolarach (plansza: $250 za $8 140). Płaci się raz (klucz księgi `inspect:{kierowca}:{key}`, powód `inspection`; drugie pytanie nic nie kosztuje), a od tej chwili ogłoszenie ma `inspected: true` i `condition` (`percent`, `defects`). W projekcie inspekcja trwa kilka minut w warsztacie z komputerem diagnostycznym albo jest płatną usługą zewnętrzną: **tu jest natychmiastową usługą zewnętrzną** (wersja z czasem i wyposażeniem warsztatu to F3).
- **Kupno: `POST /market/exchange/{key}/buy`**: cena schodzi z portfela, a auto staje w garażu z **prawdziwym stanem** (kupione bez inspekcji tak samo: stan jest wtedy niespodzianką), wszystko w jednej transakcji pod blokadą portfela (klucz księgi `listing:{kierowca}:{key}`, powód `exchange`). 201 z `bought`, a gdy już kupione, 200 z `repeated: true`. Kupione ogłoszenie znika z giełdy, a kupione auto nie staje się granym.
- **Klucz ogłoszenia** to `{miasto}.{odświeżenie}.{miejsce}.{odcisk}`; odcisk to skrót cech auta na tym miejscu i ziarna kierowcy. Serwer składa ogłoszenie od nowa z klucza i porównuje odcisk, więc **klucz nigdy nie kupuje auta, którego kierowca nie widział**: 422 `listing_gone` przy minionym odświeżeniu, kluczu zmyślonym lub cudzym, ogłoszeniu z innego miasta niż to, w którym stoi auto kierowcy, i wtedy, gdy nowy poziom gracza zmienił to, co jest na miejscach. Błędy 422 inspekcji i kupna: `listing_gone`, `insufficient_funds`, a kupna także `no_garage` i `garage_full`.
- **Dealer: `POST /market/dealer`** (`vehicle`, `request_id`): nowe auto za `price_cents` z paczki, w stanie 100%, z aut spalinowych ze źródłem `dealer`. Poziom gracza musi być co najmniej `min_player_level` auta (422 `vehicle_level_required` z `details.required` i `details.current`), a auto spoza oferty to 422 `vehicle_not_for_sale`. Powtórzone `request_id` nie kupuje drugi raz (200, `repeated: true`; klucz księgi `dealer:{kierowca}:{request_id}`, powód `dealer`). `GET /market` pokazuje ofertę od najniższego poziomu, z `open` (czy poziom gracza ją otwiera). **Czego nie ma:** asortymentu zależnego od miasta i dziennej rotacji, które podaje projekt (paczka ma dziesięć aut, więc każdy dealer sprzedaje wszystko od danego poziomu).
- **Stan auta** (`cars.condition_ppm`, domyślnie 100%: auta sprzed zmiany, wraki od Kuby i ze złomowiska oraz auta od dealera są idealne). **Skutek jest na razie jeden, częstsze awarie**: szansa awarii po okrążeniu to szansa bazowa razy `1 + breakdown.condition_factor × (1 − stan)`, czyli przy współczynniku 9 auto w stanie 50% psuje się 5,5 raza częściej, a w stanie 0% dziesięć razy (0,3% → 3% na okrążenie). Szansa wchodzi do zamrożonej konfiguracji biegu przy każdym jej zamrożeniu (start, warsztat, przyjazd, zmiana toru, naprawa, holowanie). **Mnożnik statystyk części `0,6 + 0,4 × kondycja`, serwis, naprawa stanu i przebieg liczony przez auto to F3.** Dlatego stan nie zmienia czasu okrążenia, nie ma jak go poprawić (naprawa awarii kosztuje tyle co zawsze i stanu nie podnosi), a przebieg ogłoszenia wpływa tylko na cenę i liczbę usterek, bo auto go jeszcze nie liczy. Stan jest w `GET /cars` jako `condition_percent`.
- **Dane konta.** Stan jest kolumną `cars`, więc wchodzi do kopii danych i usunięcia konta; inspekcje i kupna to wpisy księgi.

**Ryzyka i robocze liczby.** Liczby rynku są w `rules/starter.json` (część `market`) i są robocze, poza wzorem ceny, wagami usterek, 3% inspekcji i odświeżeniem co ok. 30 minut, które są z projektu. Giełda sprzedaje auto za ułamek ceny dealera (Kornet V6 za ok. $8 000 wobec $30 000 u dealera), co jest zamierzone (tanio, ale z ryzykiem), tyle że **bilansu nie sprawdza symulator ekonomii** (nie zna giełdy ani floty), a miejsce w garażu jest jedynym ogranicznikiem liczby aut, dopóki kierowca ich nie sprzedaje (sprzedaż jest opisana niżej). Rynek jest per kierowca: wymiany między graczami nie ma.

### Sprzedaż aut i magazyny: `POST /cars/{car}/sell`, `POST /market/storage/{unit}/open`

Uwagi testera: brakowało sprzedaży aut, a pomysł z **magazynami** („wojny magazynowe”: kupujesz magazyn z losowym autem w środku, może być złom albo fajna fura, szansa na dobre auto jest mała, jak ze skrzynek, ale tu pasuje do gry) dopełnia rynek. **Liczby zatwierdził użytkownik na podstawie propozycji**; są w `rules/starter.json`, część `market` (opis w `content/README.md`).

- **Sprzedaż: `POST /cars/{car}/sell`** płaci `market.resale_ppm` (**70%**) tego, ile auto było warte, **gdy kierowca je zdobył** (`cars.value_cents`: cena z giełdy, od dealera albo ze złomowiska, albo wartość auta z magazynu; wrak startowy ma cenę ze złomowiska, a auto bez zapisanej wartości liczy się jak wrak ze złomowiska), **ale nie mniej niż złom**: masa razy `market.scrap_cents_per_kg` (**0,25 $/kg**, więc Rdzak GT-4 o masie 1 000 kg to co najmniej 250 $). Wartość jest **zapisana przy zdobyciu** po to, żeby kupno i sprzedaż nie dawały zysku: wrak ze złomowiska w stanie 100% sprzedawałby się drożej, niż kosztował, gdyby liczyć go wzorem giełdy. Kupno i sprzedaż tego samego auta kosztuje więc zawsze 30% (albo więcej, gdy działa podłoga złomu).
  Wszystko dzieje się w jednej transakcji pod blokadą portfela (klucz księgi `sale:{kierowca}:{auto}`, powód `sale`). Najpierw wykonuje się to, co należne (warsztat, przyjazdy), potem **rozlicza się bieg auta, więc zarobione do tej chwili zostaje wypłacone przed sprzedażą**, części z auta wracają do inwentarza (`car_id` i `mounted_slot` puste), bieg zamyka się z `stop_reason: sold` (przystanek serwisowy, który nigdy się nie rozlicza), auto dostaje `sold_at`, a jeśli było wybrane, wybór jest czyszczony. Odpowiedź to lista floty jak zawsze oraz `sold` (`car`, `cents`).
  **Sprzedane auto nie jest usuwane**: `Car` i `RaceRun` mają globalne zakresy, które chowają sprzedane auto i jego bieg przed listami, a w bazie zostają, bo rozliczenia i księga się do nich odwołują (kopia danych i usunięcie konta widzą je przez `withoutGlobalScopes()`). Błędy 422: `car_not_found` (cudze, nieistniejące i już sprzedane są nie do odróżnienia, więc powtórzone żądanie nie płaci drugi raz), **`last_car`** (kierowca zawsze zostaje z co najmniej jednym autem) i **`car_busy`** (auto w drodze albo z niezakończonym zleceniem warsztatu; zlecenie, któremu minął czas, jest wykonane przed sprzedażą, więc blokuje tylko trwające).
- **Magazyny: `POST /market/storage/{unit}/open`** (`unit`: `small`, `medium` albo `large`; `request_id`) kupuje magazyn w mieście, w którym stoi auto grane, i **od razu go otwiera**: auto, które w nim było, staje w garażu tego miasta (potrzebne wolne miejsce: 422 `no_garage`, `garage_full`). Rodzaje: mały od poziomu gracza 3 za 1 200 $, średni od 6 za 3 500 $ i duży od 9 za 6 000 $ (422 `unit_level_required` z `details.required` i `details.current`; nieznany rodzaj to 422 `unit_not_found`). Szanse na to, co jest w środku (`market.storage.units.*.odds`, w promilach): **mały 70% złom, 25% rupieć, 4,5% dobre auto, 0,5% perełka; średni 35, 39, 20 i 6%; duży 15, 30, 40 i 15%**. Klasy (`market.storage.classes`) mają zakresy wieku, stanu i przebiegu: złom 20 do 25 lat, stan 5 do 15%, 220 do 300 tys. km; rupieć 12 do 20 lat, 20 do 45%, 150 do 280 tys. km; dobre auto 6 do 12 lat, 55 do 85%, 70 do 160 tys. km; perełka 2 do 5 lat, 85 do 100%, 20 do 70 tys. km. Auto jest losowane z aut spalinowych paczki, których `min_player_level` nie przekracza `pool_level` magazynu (3, 6 i 9; **pula jest stała dla rodzaju, nie zależy od poziomu gracza**, żeby oczekiwany zwrot ze sprzedaży nie rósł z poziomem), jego wartość liczy ten sam wzór ceny co na giełdzie (`UsedCarPricing`), a liczba usterek wynika ze stanu (jedna na 20 punktów procentowych).
  **Losowanie jest po stronie serwera** i nie da się go wyłowić: ziarno to `HMAC-SHA256("storage|{kierowca}|{rodzaj}|{numer otwarcia}", app.key)`, a numer otwarcia to liczba dotychczasowych otwarć tego rodzaju przez kierowcę, więc ani `request_id`, ani czas żądania nie wpływają na wynik, a kierowca nie może go przewidzieć bez klucza aplikacji. Każde otwarcie zapisuje wiersz w `storage_openings` (rodzaj, numer, klasa, auto, wiek, przebieg, stan, wartość, cena, id auta; unikalne po kierowcy, rodzaju i numerze), co jest śladem losowania dla skarg i dla gwarancji. **Gwarancja:** po 14 magazynach rodzaju bez dobrego auta ani perełki 15. jest dobre albo lepsze (w proporcji ich szans; `market.storage.pity_every`, licznik `until_guarantee` w `GET /market`, z `max(1, …)`), a licznik liczy od ostatniego dobrego. **Sufit małego magazynu:** perełka z małego magazynu nie jest warta więcej niż 12 000 $ (`gem_value_cap_cents`; losowanie powtarza się do 20 razy, a gdy żadne się nie zmieści, bierze najtańsze), żeby 1 200 $ nie dawało auta za ćwierć miliona.
  Odpowiedź 201 ma `bought` (id auta), `repeated: false` i **`opened`**: `class`, `vehicle` (jak w ogłoszeniu), `age_years`, `mileage_km`, `condition` (`percent`, `defects`), `value_cents` i `sale_cents` (co przyniesie sprzedaż). Klucz księgi `storage:{kierowca}:{request_id}` (powód `storage`): **powtórzone `request_id` nie otwiera drugiego magazynu** (200, `repeated: true`, `opened: null`). Wszystko w jednej transakcji pod blokadą portfela, więc brak gotówki albo miejsca niczego nie zapisuje.
- **Bilans (do sprawdzenia, nie rozstrzygnięty).** Oczekiwana sprzedaż auta z magazynu to ok. **77 do 79% jego ceny**, więc magazyn kosztuje średnio około jednej piątej ceny, a **mały z gwarancją zwraca ok. 92%** (gwarancja podnosi jego wynik bardziej, niż sugerowała propozycja: kilka punktów, a wyszło kilkanaście; zostawiłem liczby zatwierdzone). Magazyn nie jest więc źródłem zysku, tylko loterią o ujemnej wartości oczekiwanej, a wygraną jest dobre albo bardzo dobre auto za ułamek jego ceny. Symulator ekonomii nie zna magazynów ani sprzedaży: liczby sprawdza tylko `StorageUnitsTest` (rozkład, zakresy, wartość oczekiwana między 0,7 a 0,95 ceny, gwarancja, sufit). Jeśli sprzedaż okaże się farmą, zmienia się `resale_ppm` albo `odds`, a nie kod.

### Skrzynki z częściami i rzadkość: `GET /shop`, `POST /shop/crates/{kind}/open`

Krok 6 z F2 (Systemy III, punkt 11; plansza Sklep). **Liczby zatwierdził użytkownik na podstawie propozycji**; są w `rules/starter.json`, część `shop` (opis w `content/README.md`). Skrzynka to jedno losowanie części, za gotówkę z gry (nigdy za walutę premium: ryzyko R9 projektu), a bez rzadkości dawałaby to samo co sklep, więc **wchodzi sama rzadkość jako mnożnik statystyk**; afiksy, łączenie, złom, ulepszanie +N, drop z turniejów i sprzedaż części zostają w F3.

- **Rzadkość części** (`parts.rarity`: 1 Common, 2 Uncommon, 3 Rare, 4 Epic, 5 Legendary; `Rarity`) mnoży to, co część daje autu: **×1,00, ×1,10, ×1,22, ×1,38 i ×1,60** (`shop.rarity_stat_ppm`, z Systemy I) dla `grip`, `handling`, `braking` i `clearance`, a dla `power_mult` **tylko przyrost** (wydech 1,02 przy ×1,6 to 1,032, a nie 1,632). Limit skrzyni (`top_speed_cap_kmh`) nie jest przyrostem i się nie zmienia. Mnoży `Rules::scaledStats`, a potok statystyk (`RunInputsFactory`) robi to dla każdej zamontowanej części, więc rzadsza część daje szybsze okrążenie (opony ×1,38 to dla Rdzaka GT-4 ok. +30% okrążeń na godzinę na Złomowie, wg symulacji tempa). Auto mówi o częściach z rzadkością przez `Car::mountedParts()` (`slot → {slug, rarity}`), a `PartResource` zwraca `rarity` (`common` do `legendary`), `rarity_rank` (1 do 5) i `stats` z już zastosowaną rzadkością. Części ze sklepu i dotychczasowe są Common.
- **Rodzaje skrzynek** (`shop.crates.kinds`): **slotowa** (kierowca wybiera slot, cena 1,0× średniej ceny modeli, od poziomu gracza 1), **mieszana** (slot jest losowany, 1,5×, od poziomu 3) i **premium** (wybrany slot, lepsze szanse, 2,0×, od poziomu 6). Skrzynka daje **model z puli slotu: części ze `shop.parts` o najwyższym tierze, który poziom gracza otwiera** (tier 1 od poziomu 1, potem co trzy poziomy, jak u dealera: 1, 3, 6), model jest losowany po równo. Na poziomie 1 slotowa daje opony T1 albo zawieszenie T1, na poziomie 3 opony, zawieszenie, hamulce i wydech T2, na poziomie 6 opony T3 (deszczowe albo zimowe). Cena to udział w **średniej cenie modeli puli** w pełnych dolarach (opony T2 ok. 595 $, mieszana na poziomie 3 ok. 1 043 $, premium opon T3 1 500 $), więc rośnie z poziomem.
- **Szanse** (`shop.crates.odds`, w promilach): grupa **standardowa** (slotowa i mieszana) 62 / 25 / 10 / 2,7 / 0,3% (Common do Legendary), grupa **premium** 30 / 35 / 24 / 9 / 2%. **Gwarancje liczą się po otwarciach grupy** (`crate_openings`): co 10. standardowa skrzynka daje co najmniej Rare, co 40. premium co najmniej Epic (w proporcji szans rzadkości, które zostają), a **co 150. skrzynka grupy bez Legendary daje Legendary** (z Systemy VI; w tabeli skrzynek jej nie ma, a cel „pierwsza legendarna część ok. dnia 70” się na niej opiera). Licznik liczy od ostatniej części o rzadkości gwarancji albo lepszej, a legendarna gwarancja ma własny.
- **`GET /shop`** ma `data.crates`: `level` (poziom gracza), `groups` (dla standard i premium: `odds` w procentach z jednym miejscem po przecinku, `every`, `min_rarity`, `until` i `legendary_every`, `legendary_until`: ile skrzynek do gwarancji, licząc następną, czyli 1 oznacza, że następna jest gwarantowana) i `kinds` (`key`, `group`, `min_player_level`, `open`, `choose_slot`, `price_cents` dla mieszanej, i `slots`: slot, `tier`, `price_cents` dla wybieranych, `models`). Szanse widać **przed zakupem**, a odblokowany rodzaj bez slotu do dania (poziom gracza jeszcze za niski) jest `open: false`.
- **`POST /shop/crates/{kind}/open`** (`kind`: `slot`, `mixed`, `premium`; `request_id`; dla slotowej i premium `slot`) pobiera cenę, losuje część, wkłada ją do inwentarza **jako nową** (kondycja 100%) i zapisuje otwarcie, wszystko w jednej transakcji pod blokadą portfela (klucz księgi `crate:{kierowca}:{request_id}`, powód `crate`). Odpowiedź 201 to `data` (jak `GET /shop`, z licznikami po otwarciu), `repeated: false` i **`opened`**: część (`PartResource`: nazwa, slot, `tier`, `rarity`, `rarity_rank`, `stats`), `cost_cents`, `required_car_level` i `mountable` (czy auto kierowcy ma poziom na jej montaż). Powtórzone `request_id` nie otwiera drugiej skrzynki (200, `repeated: true`, `opened: null`). Błędy 422: `crate_not_found`, `crate_level_required` (`details.required` i `details.current`), `crate_slot_unavailable` (brak slotu albo slot, którego poziom jeszcze nie otwiera), `insufficient_funds`.
- **Losowanie jest po stronie serwera** i nie da się go wyłowić: ziarno to `HMAC-SHA256("crate|{kierowca}|{grupa}|{numer otwarcia}", app.key)`, a numer otwarcia to liczba dotychczasowych otwarć grupy, więc ani `request_id`, ani czas żądania nie wpływają na wynik. Rzadkość, slot (mieszana) i model losują się z tego samego ziarna po domenach (`Rng`). Każde otwarcie zapisuje wiersz w `crate_openings` (rodzaj, grupa, numer, slot, tier, rzadkość, część, cena, id części; unikalne po kierowcy, grupie i numerze), który jest śladem dla skarg i **źródłem liczników gwarancji**. Kopia danych i usunięcie konta obejmują `crate_openings`, a część w kopii ma `rarity`.
- **Co z tego wynika dla bilansu (nie sprawdzone symulatorem).** Gwarancja zawyża szanse ponad tabelę (symulacja 400 tys. otwarć): standardowa daje Rare lub lepszą średnio co 5,8 skrzynki (13,3% zamiast 10%), Epic lub lepszą co 22, Legendary co 115 (0,9% zamiast 0,3%), a premium Rare lub lepszą co 2,8, Epic co 9 i Legendary co 47; średni mnożnik statystyk części to ×1,07 dla standardowej i ×1,135 dla premium. **Rzadkość przyspiesza okrążenia** (pełny zestaw opon, zawieszenia i hamulców: Rare ok. +10 do +21% okrążeń na godzinę, Epic +16 do +36%, Legendary +18 do +57%, zależnie od toru), a symulator ekonomii tego nie zna (projekt sam mówi, że cele rzadkich części „mają trafić do symulatora”). **Pula modeli jest cienka** (w paczce jest 1–2 modele na slot i tier), więc losowość to prawie wyłącznie rzadkość, a skrzynka za 1,0× ceny to w praktyce sklep z loterią bonusu; wydech ma tylko 2% mocy, więc jego rzadkość prawie nic nie daje. Do F3 nie da się części sprzedać ani przetopić, więc zbędne leżą w inwentarzu.

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

Serwer liczy poziom gracza z XP z portfela: XP do poziomu *n* wyznacza `PlayerXpCurve`: **tabela dla poziomów 2–10** (`player.early_xp`: 60, 180, 400, 800, 1 600, 2 800, 4 800, 8 000, 12 800), prosta od poziomu 10 do `player.catch_up_level` (20) i od niego krzywa projektu `player.xp_base · n ^ player.xp_exponent` (450 i 2,3, do pełnych: poziom 20 od 442 162 XP). XP za okrążenie to 20 (Suchy Pas 10), więc poziom 2 to 3 okrążenia, poziom 5 to 40, a poziom 10 to 640 (ok. 13 h); uzasadnienie i skutki dla ekonomii są w `content/README.md` („Szybki start”). Ta sama krzywa jest w pasku HUD i w symulatorze ekonomii (test pilnuje, żeby się nie rozjechały), najwyżej `player.max_level` (30). Tor otwiera `min_player_level`. Start biegu i zmiana toru na tor, którego poziom gracza nie otwiera, to 422 `track_locked` z `details` (`required`, `current`) i komunikatem „Wymaga poziomu gracza 8 (masz 1). Zdobywasz XP, jeżdżąc.”: zasada projektu, że każda blokada pokazuje powód i ścieżkę. To jedyna bramka po stronie serwera; miasta i mapa dojdą z podróżami.

### XP i poziom auta

**XP gracza dają tylko okrążenia auta granego** (krok 7 z rozszerzenia symulatora, 2026-10-26). Dotąd każde rozliczenie okrążeń dawało XP także z aut stojących w garażu, więc flota wraków podnosiła poziom gracza kilkanaście razy szybciej, a długa gra mieściła się w połowie dni z projektu. Teraz `RunService::experienceForDriver` daje graczowi całe XP rozliczenia dla biegu auta granego (wybranego `POST /cars/{car}/select`, a bez wyboru pierwszego auta kierowcy) i `player.other_cars_xp_ppm` (0) jego części dla biegu innego auta; bieg bez auta liczy się jak grany. Pozostałe auta jeżdżą, zarabiają gotówkę i awansują same (XP auta liczy okrążenia, jak dotąd). Rozliczenie (`settlements.xp`) mówi, ile dostał kierowca, więc dla auta niegranego jest 0, a okrążenia zostają w `laps`. `select` i `GET /cars` rozliczają wszystkie auta, zanim zmienią wybór, więc okrążenia godzin sprzed wyboru należą do auta, które było wtedy grane, i nie da się zebrać XP „wybraniem auta tuż przed rozliczeniem”. Ekran Flota mówi o tym jedną linią. Reguła i model ekonomii (`model.fleet.other_cars_xp_share`) są zestawiane w `economy:simulate` dokładnie.

Auto awansuje na okrążeniach, które przejechało (projekt: „Okrążeń tym autem”). Każde rozliczone okrążenie daje autu **10 XP** (`car.xp_per_lap`), niezależnie od pogody, a XP dopisuje to samo rozliczenie, które liczy okrążenia, więc okrążenie liczy się raz i nigdy przed końcem. Pasmo od poziomu *n* do *n+1* to `200 · n` XP (`car.xp_per_level`), więc XP do poziomu *n* to `100 · n · (n − 1)`: trzeci poziom zaczyna się od 600 XP, a pasmo trzeciego poziomu ma 600, jak „410 / 600” na planszy Garaż. Nowe auto ma poziom 3 (`car.start_level`) i XP, od którego ten poziom się zaczyna. Poziom nie spada i kończy się na `car.max_level` (30, czyli tyle, ile trzeba na tier 10, który odblokowuje poziom 27). Liczby są wstępne: projekt podaje tylko, że auto awansuje na okrążeniach, a tempo ma sprawdzić symulator.

**Poziom auta ogranicza tier części**, którą auto może mieć zamontowaną: tier ≤ 1 + ⌊poziom / 3⌋ (reguła, która już była). Odmowa `car_level_required` mówi, ile trzeba i ile jest, oraz jak zdobyć poziom („Wymaga poziomu auta 6 (masz 4). Zdobywasz XP, jeżdżąc tym autem.”, zasada UX z projektu). XP auta nie dają ani statystyk („mały stały bonus” z projektu jest do zrobienia: zmieniłby zamrożoną konfigurację biegu przy każdym awansie), ani miast.

`GET /garage`: auto ma teraz `xp` (`total`, `level_start`, `next_level_at`, ostatnie `null` na najwyższym poziomie: pasek to `total` między początkiem a końcem poziomu) i `tier_unlock` (`tier` i `level`: najbliższy tier części, którego auto jeszcze nie zamontuje, i poziom, który go otwiera; `null`, gdy żaden poziom auta już go nie otwiera). Bieg (`lap`) mówi, co okrążenie daje autu: `car_xp`. Auta, które istniały przed tą zmianą, dostały XP, od którego zaczyna się ich poziom (migracja `add_xp_to_cars_table`): okrążenia przejechane wcześniej nie liczą się, bo nic ich dla auta nie zapisało. XP auta jest w kopii danych konta.

## Samouczek

Samouczek prowadzi pierwsze minuty gry SMS-ami od Kuby (mentora, który się ukrywa i pisze za każdym razem z innego numeru). **Kroki są danymi**: `content/rules/tutorial.json` (schemat `tutorial.schema.json`, teksty w pięciu językach, test `TutorialStepsTest`), więc kolejność, słowa i nagrody zmienia się bez wdrażania kodu. Serwer jest źródłem prawdy, a postęp kierowcy to wiersze tabeli `tutorial_progress` (jeden na krok, który kierowca osiągnął: `active`, `done` albo `skipped`, z czasami; krok bez wiersza nie jest jeszcze osiągnięty).

Każdy krok ma `trigger` (co go rozpoczyna, gdy poprzedni jest skończony) i `completion` (co go kończy). To fakty o grze, które serwer odczytuje ze stanu, a nie zdarzenia, na które nasłuchuje: `none`, `run_started` (jest bieg), `driving` (auto zostało wysłane i nie czeka w garażu), `lap_completed` (ukończone okrążenie), `fuel_low` (w baku poniżej jednej czwartej), `fuel_restored` (co najmniej cztery piąte), `breakdown` (auto stoi po awarii), `recovered` (już nie stoi po awarii), `acknowledged` (kierowca odpowiedział na SMS). Dzięki temu samouczek, tak jak rozliczenie biegu, jest doprowadzany do stanu bieżącego, gdy ktoś na niego patrzy, i niczego nie traci z tego, co się stało pod nieobecność kierowcy.

| Krok | Rozpoczyna | Kończy | Podświetla | Nagroda |
|---|---|---|---|---|
| 1 `wreck` | od razu | wybór wraku (jest bieg) | nic (bez SMS-a) | brak |
| 2 `start` | jest bieg | auto wysłane (`driving`) | przycisk Start w Turnieju, a gdy kierowcy tam nie ma, zakładka Turniej | brak |
| 3 `first-lap` | jest bieg | pierwsze okrążenie | okrążenia | 200 $ |
| 4 `rewards` | pierwsze okrążenie | odpowiedź kierowcy | gotówka w HUD | 100 XP auta |
| 5 `refuel` | bak poniżej 25% | bak od 80% | przycisk tankowania | 90 $ (tankowanie na koszt Kuby) |
| 6 `repair` | awaria | auto znów jedzie | przycisk naprawy | 30 $ (naprawa na koszt Kuby) |

Kroki 1–6 to pierwsze pięć z planszy Samouczek (jej krok 2, „dotyka Start na torze”, jest tu podzielony na `start` i `first-lap`), dopasowane do tego, co w grze jest. Jedno odstępstwo: krok 5 w projekcie to naprawa hamulców i „stan techniczny” części, którego nie ma, więc tu jest awaria i naprawa, a ten krok czeka na pierwszą awarię (szansa 0,3% na okrążenie, więc może długo). Kroki 6–20 (skrzynki, sloty, mapa, miasta…) dojdą z mechanikami, których dotyczą. Nagrody to gotówka z kluczem idempotencji `tutorial:{kierowca}:{krok}`, księgowana w tej samej transakcji, która oznacza krok jako zrobiony, więc nie da się ich wypłacić dwa razy. Nic w samouczku nie kosztuje kierowcy gotówki: tankowanie i naprawa są mu zwracane.

### `GET /tutorial`, `POST /tutorial/reply`, `POST /tutorial/skip`

`GET` najpierw wykonuje to, co w warsztacie należne, i rozlicza bieg, a potem przesuwa kierowcę po krokach. Krok z SMS-ami jest najpierw **pokazany**: przejście do niego kończy przebieg, a ukończyć go można dopiero przy następnym spojrzeniu, więc kierowca zawsze zobaczy wiadomość, zanim krok się skończy. Krok bez SMS-ów (pierwszy) jest wykonywany bez pokazywania. Odpowiedź to `data`:

- `status`: `in_progress`, `finished` albo `skipped`;
- `total` i `done`: liczba kroków i skończonych;
- `current`: krok, który czeka, z `key`, `number` (od 1), `sender` (numer, z którego pisze Kuba), `sms` (jedna do dwóch wiadomości w języku kierowcy), `reply` (napis przycisku odpowiedzi), `target` (`start`, `laps`, `cash`, `refuel`, `repair`: co ekran podświetla; `start` to przycisk Start, a gdzie go nie ma, zakładka Turniej) i `acknowledged` (kierowca już odpowiedział), albo `null`;
- `rewarded`: co wypłaciły kroki skończone tym wywołaniem (`key`, `cash_cents` i `car_xp`, oba zawsze są), żeby klient mógł to powiedzieć i odświeżyć gotówkę i garaż. XP auta trafia do auta kierowcy w tej samej transakcji, która kończy krok; kierowca bez auta dostaje 0.

`POST /tutorial/reply` odpowiada na SMS-y kroku, który czeka (telefon znika, a krok kończący się odpowiedzią jest skończony); dwa razy to jedna odpowiedź. `POST /tutorial/skip` zostawia bez wykonania kroki, które nie są skończone, **razem z ich nagrodami**; to, co zrobione i wypłacone, zostaje. Po `skip` samouczek już nie wraca. Obie odpowiedzi mają ten sam kształt co `GET`, a żadna nie jest buforowana. Postęp wchodzi do kopii danych konta (`game.tutorial`) i do usunięcia konta (rodzaj `tutorial steps`).

## Znane ograniczenia

- Jeden bieg na auto, a kierowca ma wiele aut i gra wybranym (opis wyżej), ale aut nie da się sprzedać ani zamienić z magazynem, a limitu aut aktywnych nie ma (wszystkie jeżdżą: ryzyko dla bilansu opisane przy garażach i przy rynku; stan auta wpływa na razie tylko na awarie, a przebieg, serwis i naprawa stanu to F3). Torów jest pięć (po jednym w mieście), mapa i podróż są (prototyp), a ekran garażu nie ma wyboru toru: do innego miasta jedzie się z mapy.
- Wyjście z sytuacji bez wyjścia (kierowca, którego nie stać na ruszenie stojącego auta) to holowanie. Wartości (5 minut czekania, paliwo na dwa okrążenia) są tymczasowe i czekają na kalibrację razem z resztą reguł. Gdy pojawi się sklep, trzeba sprawdzić, czy czas czekania nadal kosztuje więcej niż to, co holowanie oszczędza.
- `ruleset_version` zapisany w biegu pochodzi z konfiguracji (`RIDE_RULESET_VERSION`), a nie z wersji pliku zasad.
