# rules-basics: notes

Files: `api/lang/{pl,en,fr,de,pt_BR}/help/rules.rules-basics.php` (area `rules`), 43 entries each (one per pattern of `help-in/rules-basics.json`), no `options` (none of these numbers is a choice). `php -l` clean; the five files have identical keys (checked with a script). The files are generated from `scratchpad/rb/d_<locale>.py` by `scratchpad/rb/gen.py` (re-run it after editing a text; do not hand-edit the PHP unless you drop the generator).

## Sources used

- fuel, repair, breakdown, tow: `content/schema/rules.schema.json`, `Rules.php` (`breakChancePerLapPpmFor`, `wearPerKmPpb`), `RunInputsFactory::stats` (fuel use formula `(1 + 0.6 * power / reference) / 1.6`), `RunFreezer::breakdownLap`, `RunController` (refuel/repair/tow), `TowTerms`, `RunService::tow` (`fuel_laps`, `delay_ms`), `RulesConsistency` (fuel price vs `economy.json model.fuel_price`), `StarterRulesTest` (tow test, wreck stats vs ceilings, car XP levels), `docs/api-runs.md` (sections Tankowanie, Naprawa, Holowanie, Przebieg/kondycja/serwis, Stan auta).
- car: `Rules.php` (`carStartLevel`, `carRewardPpm`, `maxMountableTier`, `requiredCarLevel`, `upgradeCap`, `massFactor`, `lagFactor`, `statCeilings`), `RunInputsFactory::stats`, `CityGarages::park`, `RunService::start`, `GarageController` (ceilings), `docs/api-runs.md` (XP i poziom auta, Nagroda okrążenia zależy też od auta, D2 opóźnienie), the part/vehicle JSON of the core pack for examples (Rdzak GT-4 97 kW / 1000 kg / 175 km/h / clearance 20, stock tires 90/88/90, Orzeł V12 1700 kg, Turbo Grande lag 6).
- engines: `Rules.php` (`engineTierEfficiency`, `enginePowerKw`, `engineMassKg`, `engineBay`), `RunInputsFactory::engineMassChange`, `PartRequirements::engineLack`, `EngineCatalogTest`, `docs/api-runs.md` (Katalog silników), `content/README.md`.
- player/start/clock: `PlayerXpCurve`, `Rules::playerXpToReach`, `RunService::begin` (head start), `RunInputsFactory::make` + `GameClock` (night 22 - 5 of game time, 3 real hours a day), `RulesConsistency`.
- garage: `CityGarages` (capacity stored per garage at creation, price, wreck purchase, grant in the tutorial), `economy.json fleet`.
- premium: `Rules::speedupCoins`, `premiumProducts` (bonus computed from the first pack), `Purchases::fulfil`, `docs/api-runs.md` (P0, P1).
- Game wording from `web/src/i18n/locales/*.json` (stat names, rarities, body classes, part and car names).

## UNSURE

- `/engines/tier_efficiency_ppm_per_tier`, `/engines/layouts/*/kw_per_litre`: no live code reads them. Only `EngineCatalogTest` (and the unused-in-game `Rules::enginePowerKw`) uses them; the power of an engine part and of a car comes from `stats.power_kw` / `power_kw` in the content JSON. I wrote the help that way ("a formula the catalog test checks; changing it alone changes no car"). If a panel feature recomputes engine parts from these numbers (I found none in `app/`), the text needs a change.
- `/engines/layouts/*/base_mass_kg`, `kg_per_litre`: used live only for the mass of the FACTORY engine in `engineMassChange` (engine swap); the mass of the engine parts is written in each part (`engine_mass_kg`), also checked by the catalog test.
- `/car/xp_per_level`: no tip written. `StarterRulesTest` hard-codes `carXpToReach(2) = 200`, `(3) = 600` and a 600 band of level 3, so changing this number fails that test (not a publication gate, a developer test).
- `/car/start_level`: the help says the level applies to wrecks, exchange cars, dealer cars and storage-unit cars (all go through `CityGarages::park`); I did not trace the storage-unit case beyond `Market.php:226`.
- `/start/head_start_ms` example: "first lap about 75 s" is the base lap of the first track (`base_lap_ms` 75000) and the doc's "a lap of a minute and a quarter"; the exact lap of the Rdzak was not measured here.
- `/breakdown/chance_per_lap_ppm` example: "one breakdown per ~333 laps, ~7 h at a 75 s lap" is 1 / 0.003 laps for a perfect car; the real interval is shorter (wear, service, condition multiply it).

## Inconsistencies in the code / panel / docs that you should know

1. (FIXED by the coordinator, entry rewritten) `/garage/wreck_price_cents/*` was shown as Plain cents; it is now shown in dollars and the entry is written in dollars in all 5 locales.
2. LABELS OF `*` PATTERNS: the label of an entry replaces the field label, so the three wreck prices (and three mileages, six layouts per number, ten bays, nine `early_xp`, six reward tiers, four packs) would all get the SAME label. The help says "the position in the list is the car / layout / ..." but the panel should probably append the position name (the key or the `label_now` tail) to the label.
3. MIN OF PANEL FIELDS: the input gives `min: null` for numbers whose schema has `exclusiveMinimum: 0` (`/car/mass/exponent`, `/car/stat_ceiling/*`, `/engines/layouts/*/kw_per_litre`, `/engines/bays/*/max_displacement_l`, `/player/xp_base`, `/player/xp_exponent`), so the panel would accept 0 or negatives and the schema would reject them at save. My `range` lines say "more than 0".
4. `/player/catch_up_level`: the schema says it has to be above the last level of the table (table of 9 entries ends at level 10), but the input min is 2; the panel will not stop 5.
5. `docs/api-runs.md` (Stan auta, line ~628) still says the effect of the car condition is "one for now" and that part wear/service are "F3 to do"; later sections show they are built. Also the doc says player XP per lap is "20 (Suchy Pas 10)" while tracks carry their own `xp_per_lap`.
6. The economy model (`economy.json`) keeps its own copies of: fuel price, garage price and capacity, wreck price, the player XP curve (base, exponent, early, catch_up_level), `other_cars_xp_share`. Only fuel price, the XP of a lap, `other_cars_xp_share` and (tutorial) are compared by `RulesConsistency`; garage capacity/price and the XP curve rely on a test or on the operator. I put a "change both" line in the tips of those numbers.
