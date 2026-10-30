# Notes of the writer `rules-world` (Studio numbers: travel, regions, roads, tracks)

Files written (28 entries each, identical keys, `php -l` clean, no BOM):
`api/lang/{pl,en,fr,de,pt_BR}/help/rules.rules-world.php` (area `rules`). No `options` (none of the 28 numbers is a choice).
Generator scripts (not part of the project): `scratchpad/rw/*.py`.

## Sources per group

- `/travel/*` and `/travel/boost/*`: `api/app/Services/Travel.php` (`journeyOf`: time = km / (top speed x speed_share x boost speed), fuel = km x fuel_ml_per_km x car fuel use x boost fuel; `policeDue`: fine paid by default at `decide_ms` or at arrival), `api/app/Services/TravelPolice.php` (`plan`: chance, place between from/to, fine = max(fine_min_cents, net cents per hour x fine_minutes / 60) from `TrackChoices::forCar`; `resolve`: pay / bribe / lawyer, the bribe-fail amount is ONE posting of fine x bribe_fail_fine_ppm capped by cash; `options`: the lawyer is null when the rules have no `premium`), `Rules::travelBoost`, `rules.schema.json` (limits and descriptions), `docs/api-runs.md` (sections "Mapa i podróż" and "Podróż z przyspieszeniem i policją": the examples 26 min 03 s -> 20 min 02 s, 2.3 l -> 3.2 l, fine = 15 min of net earnings, 2 min / 5 min stops), `web/src/i18n/locales/*.json` (`police.*`, `map.boostNote`, stat and weather names).
- `/regions/*/map/*`: `rules.schema.json`, `web/src/views/MapView.vue` (BOARD = width 390, top 60, height 300: only that strip of the 390 x 716 board is drawn), `Travel::map` (x, y only go to the drawing; the route length comes from the roads).
- `/regions/*/weather_ppm/*`: `SeededWeather.php` (22.5 minute segments, sum must be 1 000 000), `PackValidator::checkWorld` (sum, weather must exist in `weather`), `starter.json` (`weather` section: rain/downpour/snow reward and XP 93%, downpour grip x0.9, fog top speed x0.92; `tire_matrix`), `docs/api-runs.md` ("Zegar gry...").
- `/roads/*/km`: `RoadMap.php` (shortest way, both directions), `Travel::start` (distance, km on the odometer and parts via `CarWear::applyDistance`), `PackValidator::checkWorld` (every city reachable from the first).
- `/tracks/*`: `TrackProfile.php` + `RunFreezer.php` (perf = sum of weight x clamp(stat / reference, 0.2, 1.6), lap = base / perf^1.15; XP per lap scaled by min(dry lap, base) / base; reward times weather), `RunInputsFactory.php` (weights / 100, tier reward multiplier, night x1.03 on unlit tracks), `TrackChoices.php` (fit_percent = base / lap, fuel vs. tank, net per hour), `CarWear`/`WearPlan` (length_m -> odometer and wear), `Rules::serviceIntervalLaps` (service every `wear.service.every_km` = 5000 km), `GameIncome.php` (track level in the income ladder), `PackValidator` (first track level 1, demand sums to 100, a reference for every weighted stat), `docs/api-runs.md` ("Tory w mieście", "XP okrążenia ma sufit toru", "Poziom gracza i tory"), `content/rules/starter.json` (real values of all examples; fuel price 200 cents/l; 75 s lap = 48 laps/h, net $600/h with fuel $3 per lap).

## UNSURE

- `/travel/boost/police_from_ppm`, `/travel/boost/police_to_ppm`: the code takes `to - from` as the span and nothing in the panel code, the schema or `PackValidator` checks `from <= to` (only a test on the starter rules does, `StarterRulesTest`). The help says "do not set it above/below the other"; I could not establish what the Studio itself does when a value crosses the other.
- Section names used in the tips ("sekcja Drogi", "Podróże", "Paliwo", "Pogoda", "Przyspieszenie") are the section names as I assume the Studio shows them; I did not open the Studio to confirm the exact labels.
- "Bramka M3" is quoted from `docs/api-runs.md` ("żadne auto nie wygrywa więcej niż dwóch torów jednego rodzaju"); I did not read the gate code itself.
- `/regions/*/weather_ppm/*` is now written in PERCENT (the Studio shows it as %; sum of a city = 100%, schema minimum 1 ppm = 0.0001%), per the coordinator's correction. The weather key list of a city is fixed by the Studio: I could not establish whether the Studio lets an operator add or remove a weather line; the help only says a weather with chance 0 cannot be listed (schema minimum 1).

## Inconsistencies and things the operator should know

- Map board: the schema and the docs say the board is 390 x 716, but the app (`MapView.vue`) draws only the strip y = 60..360 (390 x 300). A city set outside that strip (e.g. y = 500) is valid for the schema and invisible on the map. The y help says so.
- `/travel/fuel_ml_per_km` schema description says "a car of the thirst of the track fuel use (1.0)"; the code multiplies by the fuel use multiplier of the CAR (`CarStats::fuelUseMultiplier`), not of a track. The help says "a car of ordinary fuel use".
- `/travel/boost/bribe_fail_fine_ppm`: the schema says "the bribe is lost, and the fine is written", which reads as bribe + fine. The code charges ONE amount: `min(fine x bribe_fail_fine_ppm, cash)` (docs: "płaci się 2 mandaty"), so 200% is the total, not on top of the bribe. The help follows the code. The schema allows a value below `bribe_ppm` (min 100% each), which would make a failed bribe cheaper than a working one; nothing validates it.
- `/travel/boost/police_from_ppm` / `police_to_ppm` and the other boost numbers are, per the docs, "moje propozycje do skalibrowania w symulatorze (P5)": the speed help says they are uncalibrated.
- `docs/api-runs.md` says "pozycja auta na mapie to prosta między miastami", so x / y never feed the route length; the help for x / y states that the drawing does not change times.
- `/tracks/*/reference/*` has no unit in the schema (the statistics are plain numbers; `car.stat_ceiling` in the rules caps accel 400, top speed 450, grip/handling/braking 250, clearance 200) and nothing ties a reference to those ceilings; the help does not mention the ceilings.
- The panel's old label for `base_lap_ms` was "Okrążenie wzorcowe (ms)"; the Studio shows seconds, so the new label says (s).
