# Notes of forms-world-game

Files written (5 locales x 4): `api/lang/<locale>/help/world.php` (31 entries), `game.php` (11), `wizard.php` (3), `guide.forms-world-game.php` (3 guides: world, game, wizard; 5 steps each). 48 entries and 27 option lines per locale. `php -l` clean; a PHP check (same keys and options in the five languages, label/help filled, same `:min` placeholders, fr: no plain space before `: ; ? !` and no straight apostrophe) found no problem. The files are generated from `help-gen-fwg/data_<locale>.py` by `help-gen-fwg/emit.py` (scratchpad), `check.php` is the check.

## Sources used

- World form (cities, climate seed, map, weather): `WorldFields.php`, `WorldForm.php` (percent x 10 000 = ppm; reward / 100 = cents), `rules.schema.json` (regions, roads, tracks, weather, travel), `PackValidator::checkWorld` (demand sum 100, every demand stat has a reference, weather chances sum EXACTLY 1 000 000, every city has a track, every city reachable from the FIRST city of the list, no road to itself, first track open from level 1), `SeededWeather` (cumulative thresholds in the order of the lines, roll from seed + segment number), `RunFreezer` (lap time = base / perf^1.15, perf = sum(share x clamp(stat/reference, 0.2, 1.6)); XP share), `TrackProfile`, `Surface`, `RoadMap` (both ways, Dijkstra), `Travel::journeyOf` (time = km / (top speed x speed_share), fuel = km x fuel_ml_per_km x thirst), `DefinitionUsage::removedInUse` (used by `ContentDrafts` on a draft save and by `ContentPublisher`), `MapPoint` and `web/src/views/MapView.vue` (board 390 x 716, the screen shows y 60-360), `Rules::homeRegion/startingTrack`, `Market.php` (market of the city where the car stands), `docs/api-runs.md` (tracks T1/T2, map and travel, game clock and night), `content/rules/starter.json` (examples), `web/src/i18n/locales/*.json` (stat, weather, surface, kind names), old `admin.php` world texts in five languages.
- Game form and wizard: `GameResource.php`, `CreateGame.php`, `EditGame.php`, `HandlesGameIcons.php` (square, >= 192 px), `ContentImages` (1 MB, up to 2048 px), `GameCreator.php` (template / copy / none, warnings, draft status), `DomainPage.php`, `DomainJobs.php`, `DomainChecker.php`, `Games::byHost/forRequest/default` (a draft answers to nobody, only a live game answers on its domain), `GameController` (theme_color -> meta theme-color, manifest theme_color and background_color; locale; short name of 12 letters), `BrandingController` (link preview colour), `RunsInAGame` (`--game`, default game), `Game` model, old `admin.php` games texts, `HelpCatalogTest`.

## Facts established (the ones the brief asked for)

- Weather chance: they are CHANCES, not relative weights. A city is refused unless the lines add up to exactly 1 000 000 ppm = 100% (`PackValidator`, `SeededWeather`). Entered as a percent with up to 4 decimals (1 ppm = 0.0001%). A segment is 22.5 min, 8 per game day (a game day = 3 real hours). The order of the lines changes which segment gets which weather (cumulative thresholds).
- Reference lap is entered in ms (75 000 = 75 s); fuel in ml; reward in dollars (stored as cents per lap); length in m.
- A city opens at the LOWEST `min_player_level` among its tracks (docs); garages, workshop (own queue), exchange, dealer, wreck yard and storage units are per city; travel needs a garage with a free place in the destination.
- `kind` changes no number by itself (only a label in the track picker and the grouping of the M3 balance gate: no car wins more than two tracks of one kind).
- `reference_tires` changes only the reference car of the bots (`BotProfileTable`), not what players may mount.
- `demand` = shares in percent adding up to 100; `reference` = the value at which ratio = 1 (neutral); the ratio is clamped to 0.2 - 1.6.
- Night: a lap that starts at night on an unlit track takes 3% longer (`clock.night_lap_ppm`); night 22:00-5:00 game time = about 52 real minutes of every 3 hours.
- Game: a draft answers to nobody on its domain (`Games::byHost` takes live games only); a single-game installation answers on any host; the default game is what `--game`-less commands and an unclaimed host in development use, and (without a live pack) it reads the repository files.
- Slug of a game cannot change because commands (`--game`), deploy scripts, the audit trail (`game:{slug}`) and the panel's game switcher name the game by it. The form disables the field on edit.

## UNSURE

- `world.tracks` tip, "no rivals until the table of the bots has a row for it (economy:bot-profiles)": `BotProfiles` says a track the table does not know has no bots, and the only code that makes the table is the console command `economy:bot-profiles`. I did not find where (or whether) a published pack made in the panel gets the table recomputed; if publication does it, the sentence should go.
- `world.weather_kind` options: the "7% / 10% / 8%" numbers are the car game's values of `weather.*` in `starter.json` (written as "the car game's numbers"); a game with other rules will show wrong numbers there.
- `world.km` help states "half of top speed" and "60 ml per km" as the CURRENT travel numbers (`travel.speed_share_ppm`, `fuel_ml_per_km`); they are Studio numbers.
- `world.lit` and `world.reward`/`xp_per_lap` tips cite the current values 3% / 7% (starter.json).
- `world.xp_per_lap`: XP goes to the driver (player level; `RunService::experienceForDriver`); cars also level on `car.xp_per_lap`, which I did not mention.
- `game.theme_color`: label changed to "Frame colour" (Kolor ramki) because the old "Kolor tytulu" (colour of the title) is not what it does; the effect list is from the manifest, `meta theme-color`, and the share page. I did not check the installed-app splash screen on a real device.
- `game.locale`: "also the language of the page and the app when the visitor's language is not one of the game's" comes from the docblock of `GameController::show`, not from reading the web code.

## Inconsistencies in code or docs

1. Old hint `admin.world.cities_hint` says "the first city is where new drivers start". The code starts a new driver in the city of the FIRST TRACK (`Rules::homeRegion()` = region of `startingTrack()`); the first city of the list is only the root of the road-reachability check. The help says it that way.
2. `admin.world.ppm` ("Chance (ppm)") and the `weather_hint` in `admin.php` ("in parts per million") are stale: the field is a percent now.
3. `GameResource::HOST` (edit form) accepts a single label host, the wizard uses `DomainChecker::HOST` (two parts at least). A certificate needs two parts; the help says "at least two parts".
4. Options of `world.stat` (6 lines) and `world.weather_kind` (6 lines) are rendered by `FieldHelp::html` as a bullet list under EVERY select of the repeaters (up to 6 + 6 selects x 6 bullets). `HELP_DYNAMIC_CHOICES` contains `world.stat`, so the test does not need them; I wrote them anyway (the stat enum is fixed by the schema, and the brief asked for every option). Suggest showing the list only once (in the tip of the repeater) or giving these two selects only labels.
5. `wizard.start`: the Radio has its own `->descriptions()` (admin.games.wizard.start_*_hint) and the catalog options are listed under it as well, so each choice is said twice.
6. `from`, `to`, `track_region`, `reference_tires` (cities, parts) and `wizard.copy_from` (games) are dynamic: I wrote NO options for them (listing the five cities of the car game would show them in every game; `HelpCatalogTest` excludes them).
7. `MapPoint::MAX_Y` / schema allow y up to 716 but the map screen draws only y 60-360 (`MapView.vue` BOARD), so a city at y < 60 or > 360 is valid and invisible. Written as a tip.
8. `GameResource::SLUG` needs a letter first and at most 32 characters, but the wizard fills the slug with `Str::slug($name)` (may start with a digit or be longer): the user sees a validation error, not a bug.
