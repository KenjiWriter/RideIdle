# Rozliczanie (settlement)

Implementacja specyfikacji „Rozliczanie” z płótna projektowego. Kod jest w `api/app/Domain/Settlement/` i nie zależy od frameworka, bazy, zegara ani losowania. Dzięki temu wynik jest taki sam na każdej maszynie i bazie, a funkcję da się testować i uruchamiać w symulatorze bez żadnej infrastruktury.

## Klasy

| Klasa | Rola |
|---|---|
| `Settler` | `settle(RunState, now, RunConfig)` oraz `settleInChunks(...)` (okna po 72 h) |
| `RunState` | Kursor (początek bieżącego okrążenia, ms UTC), liczba okrążeń, paliwo w ml, okrążenie awarii |
| `RunConfig` | Zamrożona konfiguracja: czas okrążenia, nagroda (centy) i XP na pogodę, zużycie paliwa, harmonogram pogody |
| `WeatherSchedule` | Pogoda w segmencie 22,5 min (`ConstantWeather`, `CallbackWeather`; prawdziwa z klimatu regionu dojdzie później) |
| `SettlementEvent`, `EventType` | Wpisy logu: `lap_batch`, `breakdown`, `fuel_empty` z priorytetami |
| `SettlementResult` | Nowy stan, uporządkowany log, sumy do zaksięgowania, `stopReason()` |
| `Ppm` | Mnożniki w ppm, zaokrąglanie połówki od zera |
| `TournamentPayout`, `PayoutResult` | Podział puli turnieju (rake 10%, reszta dla zwycięzcy) |
| `EloTable`, `Rating` | Respekt: oczekiwany wynik z tablicy, K, dywizje, miękki reset sezonu |

## Przykład

```php
$config = new RunConfig(
    lapMs: ['dry' => 75_000, 'rain' => 90_000],
    lapCashCents: ['dry' => 1_550, 'rain' => 1_442],
    lapXp: ['dry' => 25, 'rain' => 23],
    fuelPerLapMl: 1_500,
    weather: $weatherOfTheRegion,
);

$result = (new Settler())->settle(new RunState($cursorMs, $lapsDone, $fuelMl, $breakAfterLaps), $nowMs, $config);

$result->cashCents;      // do księgi, w centach
$result->state;          // nowy stan do zapisu
$result->events;         // log: podsumowanie, powiadomienia, telemetria
$result->stopReason();   // EventType::Breakdown, EventType::FuelEmpty albo null
```

Zasady, które trzeba zachować po stronie kodu wołającego: `now` czytamy z serwera po założeniu blokad, wartości w `RunConfig` liczymy raz przy zamrożeniu konfiguracji (z `float` wolno tylko tam), a po tankowaniu lub naprawie ustawiamy `cursorMs = now`.

## Wektory testowe i gdzie są sprawdzane

| Wektor | Co sprawdza | Test |
|---|---|---|
| W1 | Paliwo ogranicza jazdę, log z `fuel_empty` | `tests/Unit/Settlement/SettlerVectorsTest.php` |
| W2a, W2b, W2c | Okrążenie w toku, kompozycja dwóch rozliczeń | `SettlerVectorsTest.php` |
| W3 | Granica segmentu pogody | `SettlerVectorsTest.php` |
| W4 | Awaria i pusty bak po tym samym okrążeniu | `SettlerBreakdownTest.php` |
| W5 | Zaokrąglanie nagrody przed zamrożeniem | `PpmTest.php` |
| W6a, W6b | Pula turnieju bez reszty i z resztą | `TournamentPayoutTest.php` |
| W7 | Zmiana Respektu | `RatingTest.php` |

## Testy własności (`SettlerPropertyTest.php`)

Dziesięć tysięcy losowych biegów z ustalonym ziarnem (`20261022`, numer przypadku jest w komunikacie błędu). Porównanie z symulacją okrążenie po okrążeniu, kompozycja (`settle(t1)` potem `settle(t2)` równa się `settle(t2)`), idempotencja, limity paliwa i awarii, spójność logu, monotoniczność oraz równość rozliczania w oknach z rozliczaniem jednorazowym. Celowo zepsuta formuła (zaokrąglanie w dół zamiast w górę) jest wykrywana przez wektory i przez test symulacji.

Dodatkowo testy architektury (`tests/Arch/DomainArchTest.php`) pilnują czystości domeny, a skan tokenów (`IntegerArithmeticTest.php`) wyklucza arytmetykę zmiennoprzecinkową w plikach, które decydują o wyniku.

## Tablica oczekiwanych wyników Respektu

`content/tables/elo-expected-ppm.json` jest generowana poleceniem `php artisan content:elo-table`, a `--check` (i test) wykrywa nieaktualny plik. Kod gry nie liczy potęg w czasie działania.

## Zamrażanie konfiguracji (`api/app/Domain/Run/`)

Rozliczanie czyta tylko liczby całkowite, które `RunFreezer` policzył raz: przy starcie biegu i za każdym razem, gdy zmienia się auto, część, tor albo stan serwisu (zawsze po rozliczeniu do tej chwili).

| Klasa | Rola |
|---|---|
| `RunFreezer` | `freeze(RunInputs, ziarno, okrążenia)` zwraca `FrozenRun`. Jedyne miejsce, gdzie wolno użyć `float` (oprócz tablicy Respektu) |
| `RunInputs`, `CarStats`, `TrackProfile`, `WeatherEffect` | Dane wejściowe: statystyki auta, wagi i wartości odniesienia toru, mnożniki pogody |
| `FrozenRun` | `config` (to, co ląduje w kolumnie `race_runs.config`) i okrążenie planowanej awarii |
| `SeededWeather`, `Rng` | Pogoda segmentu z ziarna klimatu regionu: pierwsze 32 bity `sha256("ziarno\|domena\|indeks")` modulo 1 000 000 |

Czas okrążenia to `baza / perf^1,15` zaokrąglone do ms, gdzie `perf` to suma wag razy statystyka z pogodą podzielona przez wartość odniesienia, obcięta do 0,2–1,6. Nagroda i XP na pogodę to `Ppm::mul`. Awaria jest zaplanowana na pierwsze okrążenie po wykonanych, którego rzut z ziarna biegu jest mniejszy od szansy.

## Zapis w bazie (`api/app/Services/`)

Tabele (migracje `2026_10_22_19*`): `wallets` (saldo i XP kierowcy, `CHECK cash_cents >= 0`), `race_runs` (stan biegu, zamrożona konfiguracja i `state_version`), `settlements` (unikalne `(race_run_id, state_version)`), `settlement_events` (log, unikalne `(settlement_id, seq)`) i `ledger_entries` (księga, unikalny `idem_key`).

| Operacja | Co robi |
|---|---|
| `Ledger::post` | Jedyny sposób zmiany salda. Blokuje portfel, sprawdza klucz idempotencji (ponowione księgowanie zwraca pierwszy wpis, ten sam klucz z inną kwotą to `IdempotencyConflictException`), nie pozwala zejść poniżej zera |
| `RunService::start` | Zamraża konfigurację z nowym ziarnem, kursor = teraz |
| `RunService::settle` | Jedna transakcja: rozliczenie w oknach, zapis rozliczenia z logiem, księgowanie z kluczem `settle:{bieg}:{wersja}`, XP, nowy stan biegu i `state_version + 1`. Drugie wywołanie w tej samej chwili nic nie zmienia |
| `RunService::reconfigure` | Rozlicza, potem zamraża nową konfigurację. Jadący bieg rusza od teraz (okrążenie w toku przepada, żeby zmiana nie działała wstecz). Ziarno zostaje, więc ponowny zapis tych samych ustawień nie losuje awarii od nowa. Zatrzymany bieg zostaje zatrzymany |
| `RunService::refuel` | Rozlicza, potem obciąża księgę (cena zaokrąglona w górę do centa, nie więcej niż mieści bak) i dolewa. Klucz `refuel:{bieg}:{id żądania}`: to samo żądanie ponowione nic nie zmienia. Brak środków cofa wszystko, razem z rozliczeniem. Bieg bez paliwa rusza od teraz, po awarii zostaje zatrzymany do naprawy |
| `RunService::repair` | Rozlicza (okrążenia do awarii są płatne według starej konfiguracji), potem zamraża konfigurację z **nowym ziarnem**, czyli z nowym, niezależnym planem awarii, i rusza od teraz. Naprawia tylko awarię: jadący bieg albo bieg bez paliwa zostaje bez zmian, więc naprawą nie da się przelosować planu. Jeśli bak jest też pusty, bieg zostaje zatrzymany z powodu paliwa, a ruszy go tankowanie. Naprawa może kosztować (`$costCents`): płatna po rozliczeniu i tylko wtedy, gdy naprawa naprawdę się odbyła, z kluczem `repair:{bieg}:{id żądania}` (id jest wtedy wymagane). Brak środków cofa wszystko |

Zasady, które kod pilnuje i które testy współbieżności sprawdzają na prawdziwej bazie:

1. **Kolejność blokad: portfel kierowcy, potem bieg.** Zawsze. Dzięki temu dwa żądania nie czekają na siebie nawzajem.
2. **Zegar czytamy po blokadach.** Kto czekał na blokadę, dostaje prawdziwy czas swojej kolejki.
3. **Portfel zakładamy przed transakcją** (`Ledger::ensureWallet`). Test współbieżności wykrył, że założenie go w transakcji po odczycie z blokadą nieistniejącego wiersza kończy się zakleszczeniem w InnoDB (każda transakcja trzyma blokadę przerwy, która blokuje wstawienie pozostałych). Zdarzyłoby się przy pierwszych żądaniach nowego kierowcy.
4. **Zakleszczenia są ponawiane** (3 próby) na zewnętrznej transakcji jako dodatkowe zabezpieczenie.
5. **Wszystko albo nic**: błąd na dowolnym kroku cofa rozliczenie, log, księgę i stan biegu.

### Testy zapisu

- `tests/Feature/Settlement` (SQLite i prawdziwe bazy): wartości W1 i W2 w bazie, awaria, atomowość, duplikaty, długa nieobecność, zamrożenie, tankowanie. Najważniejsze reguły (rozliczenie przed obciążeniem, kursor od teraz, zachowanie ziarna, ponowione żądanie, zaokrąglanie w górę) sprawdzono, psując kod i patrząc, że testy padają.
- `tests/Concurrency` (osobne procesy PHP na MySQL, MariaDB i PostgreSQL, pomijane na SQLite): osiem procesów księguje ten sam klucz, wydaje saldo, rozlicza ten sam bieg, tankuje tym samym żądaniem i naprawia tym samym żądaniem (płatnym i darmowym) naraz, a mieszanka rozliczeń, tankowań i napraw na jednym biegu kończy się sprawdzeniem sum: księga równa rozliczeniom i kosztom paliwa i napraw, okrążenia równe rozliczonym, paliwo równe kupionemu minus przejechane, wersja równa liczbie zmian.

## Czego jeszcze nie ma

- Zmiana auta na bieg w trakcie.
- Cena paliwa, pojemność baku i koszt naprawy z paczek treści (na razie parametry `refuel` i `repair`), prawdziwy harmonogram pogody z klimatu regionu.
- Punkty końcowe HTTP dla biegu (start, podsumowanie, tankowanie, zmiana ustawień).
- Rundy z pozycją (`round_complete`) i dropy z ziarna (`sha256`), kursy i podróże, cykl jazda i ładowanie EV.
