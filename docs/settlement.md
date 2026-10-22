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

## Czego jeszcze nie ma

- Zamrażanie konfiguracji z wzorów statystyk (`RunConfig` z auta, części, toru i pogody) oraz `resume(now)` po tankowaniu i naprawie.
- Rundy z pozycją (`round_complete`) i dropy z ziarna (`sha256`), kursy i podróże, cykl jazda i ładowanie EV.
- Zapis w bazie: tabele `settlements`, `settlement_events`, `ledger`, transakcja z `lockForUpdate()` i kluczem idempotencji.
- Prawdziwy harmonogram pogody z klimatu regionu.
