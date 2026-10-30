# Notes of `forms-vehicle-part`

Delivered: `api/lang/{pl,en,fr,de,pt_BR}/help/{definition,vehicle,part,guide.forms-vehicle-part}.php` (20 files, `php -l` clean, keys and option keys identical in all five locales, checked by a script that also compares them with the input JSON).
Counts: 40 entries (definition 5, vehicle 20, part 13, guide 2) and 90 option lines (vehicle 34: class 10, drivetrain 3, powertrain 2, engine_layout 6, tier 10, sources 3; part 56: slot 10, tire_kind 6, tier 10, stat_name 18, fits_powertrain 2, fits_classes 10). `:min` / `:max` are used only in `definition.image_upload` (range). In fr, NO-BREAK SPACE before `: ; ? !`, before `%`, inside « », between number and unit; typographic apostrophe.

The files were generated from scratchpad sources (`scratchpad/gen/*.py`, `gen.py`); the PHP files are the deliverable.

## Sources used

- Car fields: `content/schema/vehicle.schema.json` (ranges, ice/ev conditionals), `content/packs/core/vehicles/*.json` (examples, the factory power vs `engines` rule), `api/app/Content/RunInputsFactory.php` (`stats`, `power`, `engineMassChange`, `tankMl`, `vehicle()`), `Rules.php` (`massFactor`, `drivetrain*`, `engineBay`, `engineLayout`, `carRewardPpm`, `wearPerKmPpb`), `content/rules/starter.json` (`car`, `engines`, `drivetrain`, `market.pricing`, `fuel`, `wear`), `docs/api-runs.md` (drivetrain, engine catalogue, market, fleet sections), `content/README.md`, `PackValidator::checkVehicleRules` (min_player_level = max(1, 3*tier-3)), `Domain/Market/UsedCarPricing`, `Services/Market.php`, `Catalog::startingVehicles`, `StarterCarController`.
- Part fields: `content/schema/part.schema.json`, `content/rules/model.json` + `model.schema.json` (slots, roles, hidden ecu), `web/src/i18n/locales/*.json` (`slot.*`, `part.stat.*`, `shop.compare.stat.*`, `fleet.class`, `car.drivetrain`, `carStat.*` for the names in the five languages), tire matrix in `content/rules/starter.json` and `content/README.md`, `Services/PartRequirements.php`, `Rules::scaledStats`, `docs/web-run.md` (which direction is better per stat), real parts in `content/packs/core/parts/*.json`.
- Part price curve: `content/rules/economy.json` (`anchors.new_car_by_tier`, `parts.set_share`, `slot_share`, `consistency.tolerance`), `Domain/Economy/RulesConsistency.php`, `Console/Commands/SimulateEconomy.php` (engine group excluded), `Content/Publishing/PackGates.php`.
- Labels of the old panel: `api/lang/pl/admin.php` `definitions.*`; how entries are read: `api/app/Filament/Support/FieldHelp.php`.

## What reads which part statistic (verified in `RunInputsFactory::stats/power/engineLoad/tankMl` and `Rules::scaledStats`)

- Read: `grip` (tires role only), `handling` (tires + suspension), `braking` (tires + brakes), `clearance` (suspension, averaged with the body), `power_mult` (any slot; the booster's gain is grown by the intercooler's `boost_gain`), `power_kw` and `engine_mass_kg` (engine), `top_speed_cap_kmh` and `launch` (gearbox), `lag` (booster), `fuel_mult`, `engine_load`, `mass_kg` (any slot), `boost_gain` (cooling slot, only with a booster), `capacity_l` (tank).
- NOT read by any formula: `cooling`, `fade_resistance`, `gears` (only displayed in the web). Written as "information only" in all locales.
- Rarity / +N / condition scale only `grip handling braking clearance power_kw` and the gain of `power_mult`.

## UNSURE

- `vehicle.engine_tune`: no code reads it (grep over `api/app`); the only link is the convention power_kw = kW/l x litres x (1 + 7.5% (tier-1)) x tune, which `EngineCatalogTest` checks for the core pack. The schema gives no description. Written as "no formula reads it today".
- `vehicle.cargo_kg`, `vehicle.seats`: no formula reads them; `cargo_kg` only goes into the `GET /starter-cars` payload (the web type has it but no screen shows it); `seats` is read nowhere. Written as catalog data with no effect on income.
- `vehicle.description`: found only in `StarterCarController` (the starter-wreck choice); another consumer (web, share page) was not searched for.
- `vehicle.engine_motors`, `battery_kwh`, `max_charge_kw`, `powertrain = ev`: nothing reads them; `RunInputsFactory::vehicle()` throws for a non-ice car and dealer/exchange/storage/bots skip them. The sentence "do not give an EV the source start" is an inference (the starter-car screen freezes every `start` car through `RunInputsFactory::make`, which throws); not run.
- `part.price` tip: "the publication gate stops the pack when a price is off the curve" is inferred from `PackGates` running `economy:simulate --strict` and `RulesConsistency` comparing every non-engine-group part with 20% tolerance; I did not run the gate.
- `part.slot` / `ecu`: no part in the core pack uses the hidden slot; what a part there may carry was not established beyond "a slot with no role holds parts whose statistics the formulas read (power_mult)".
- `part.tier` option lines use the rule "car level >= 3 x (tier-1)" (`Rules::requiredCarLevel`) and the shop shows one tier above (`maxMountableTier + 1`, docs); the exact shop rule for the played car was taken from `docs/api-runs.md`, not re-read in `Shop.php`.
- Tier 9: `car.reward_ppm_from_tier` has no entry, so it pays like tier 8 (`carRewardPpm`); the bot-profile table has no tier-9 column until the next publication rebuilds it from the candidate pack (not verified that the rebuild adds tier 9).

## Inconsistencies / things to know

1. `part.fits` (powertrain, classes) is NOT enforced: only `TrackSimulator::fits` reads it. The workshop (`PartRequirements`), the shop and `PartPreview` ("Dopasowania części do auta nie jest sprawdzane") ignore it, although the schema calls it a "restriction". The help says so honestly. If the panel is supposed to enforce it, that is a missing feature.
2. The part form's `slot`, `tire_kind`, `fits_powertrain` etc. still build their selects from raw values (`self::options([...])`); only `stat_name` goes through `FieldHelp::options`. The option labels of this catalog are only used where the form calls `FieldHelp::options` (the helper text lists every option with its label and line anyway).
3. `engine.tune`, `cargo_kg`, `seats`, `engine_motors`, `battery_kwh`, `max_charge_kw` are required form fields (for the right powertrain) that no formula reads: an operator has to type them with no effect on the game (except `cargo_kg` in the starter-car payload).
4. The part's engine `power_kw` / `engine_mass_kg` have to follow the `engines` rules, but only `EngineCatalogTest` (core pack) checks it; `PackValidator` and the panel do not. An engine part with another power is accepted and drives with the number typed.
5. Old label `definitions.min_player_level` says "Poziom dealera"; the number also gates the exchange and the storage-unit pools (`min_player_level <= pool_level`), so the new label is "Poziom gracza (dealer)".
6. `docs/api-runs.md` line ~165 still lists the stock shop as "tires, suspension, brakes, exhaust" while the shop list now has 71 parts (engines, boosters, cooling, gearboxes): older text, harmless.
7. A new part reaches the shop only through `shop.parts` in the rules (editable only in "Zasady jako tekst"); the part form has no switch for it. The part guide says so.
