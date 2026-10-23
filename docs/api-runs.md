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
      "xp": { "dry": 25, "rain": 23 },
      "fuel_ml": 1500
    },
    "server_time_ms": 1792692960123
  },
  "wallet": { "cash_cents": 46500, "xp": 750 }
}
```

`status` to `running` albo `stopped`, a `stop_reason` to `fuel_empty`, `breakdown` albo `null`. `prices` to ceny akcji biegu w centach (litr paliwa i naprawa), żeby przycisk mógł podać koszt przed naciśnięciem. `cursor_ms` to początek bieżącego okrążenia. `state_version` rośnie przy każdej zmianie stanu i jest tym, co klient odsyła jako `since_version`.

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

### Wybór wraku: `GET /starter-cars`

Trzy wraki startowe, od najtańszego (to on jest polecany na start), i tor, na którym zacznie się bieg (`track`). Każde auto ma `name`, `description`, `class`, `drivetrain`, `engine` (`layout`, `displacement_l`), `power_kw`, `mass_kg`, `top_speed_kmh`, `cargo_kg`, `tank_l` oraz `lap`: czas okrążenia, nagrodę, XP i zużycie paliwa w najczęstszej pogodzie na torze. Liczby `lap` policzył ten sam kod, który zamraża bieg, więc ekran wyboru pokazuje to, co auto naprawdę pojedzie.

### Start: `POST /runs`

```json
{ "car": "rdzak-gt-4", "track": "zlomowo-street" }
```

`car` musi być wrakiem startowym z paczki treści (`sources` zawiera `start`), `track` torem z `content/rules/starter.json`. Odpowiedź to 201. Kierowca, który ma już bieg, dostaje 409 z `code: run_exists` i `run_id`. Dwa równoległe żądania tego samego kierowcy nie założą dwóch biegów (blokada portfela, sprawdzone na osobnych procesach).

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

Rozlicza i wypłaca jazdę ze starym torem, potem zamraża nowy i auto jedzie nim od teraz. Okrążenie w toku przepada, żeby zmiana nie działała wstecz. Planowana awaria nie przesuwa się (ziarno biegu zostaje). Zatrzymane auto zostaje zatrzymane. Wysłanie tej samej zmiany w tej samej chwili nic nie płaci dwa razy.

### Naprawa: `POST /runs/{id}/repair`

```json
{ "request_id": "9a8f3c10-77b2" }
```

Naprawia auto po awarii za koszt z zasad (30 $), po rozliczeniu, więc zarobek sprzed awarii może za nią zapłacić. Auto dostaje nowy plan następnej awarii (nowe ziarno) i rusza od teraz. Odpowiedź ma `repair`: `repaired`, `cost_cents`, `repeated`. Auto, które się nie zepsuło, zostaje bez zmian i `repaired` jest `false`, więc naprawą nie da się losować awarii od nowa. Jeśli bak jest też pusty, auto zostaje zatrzymane z powodu paliwa i rusza je dopiero tankowanie.

## Ponawianie i idempotencja

`request_id` chroni przed podwójnym obciążeniem przy ponowieniu żądania po zgubionej odpowiedzi: to samo żądanie ponowione zwraca `repeated: true` i niczego nie zmienia, także gdy w międzyczasie stan się zmienił (na przykład auto zepsuło się drugi raz). Nowy zamiar to nowy `request_id`. Kluczem w księdze jest `refuel:{bieg}:{request_id}` albo `repair:{bieg}:{request_id}`.

## Błędy

| Status | `code` | Kiedy |
|---|---|---|
| 401 | | Brak albo zły token |
| 404 | `no_run` | Kierowca nie ma biegu albo bieg nie jest jego |
| 409 | `run_exists` | Start, gdy kierowca ma już bieg (`run_id` w odpowiedzi) |
| 422 | `insufficient_funds` | Za mało gotówki na tankowanie lub naprawę (`available_cents`, `required_cents`). Nic się nie zmienia, nawet rozliczenie |
| 422 | | Błędne dane: nieznane auto lub tor, brak `request_id`, `liters` poniżej 1 lub nie całkowite, zła `since_version`. Szczegóły w `errors` |

Komunikaty (`message`) są w języku żądania.

## Skąd są dane

Auto pochodzi z paczki `content/packs/core`, a tor, klimat regionu, efekty pogody, cena paliwa, koszt naprawy i szansa awarii z `content/rules/starter.json` (opis w `content/README.md`). Dopóki nie ma wyboru części i opon, każde auto jedzie na oponach fabrycznych, a jego statystyki wynikają z nadwozia: przyspieszenie to moc na tonę, prędkość maksymalna z definicji. Wartości bez źródła w projekcie (koszt naprawy, szansa awarii) są tymczasowe.

## Znane ograniczenia

- Jeden bieg na kierowcę i jeden tor (Złomowo). Kolejne tory wymagają podróży i poziomów gracza, których jeszcze nie ma.
- Nie ma ochrony przed sytuacją bez wyjścia: kierowca bez gotówki i bez paliwa albo z awarią, na którą go nie stać, nie może ruszyć. Dziś zawsze zarabia, zanim cokolwiek wyda, ale po wprowadzeniu sklepu trzeba to rozwiązać (na przykład holowanie albo darmowa naprawa).
- `ruleset_version` zapisany w biegu pochodzi z konfiguracji (`RIDE_RULESET_VERSION`), a nie z wersji pliku zasad.
