<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Economy\EconomyRules;
use App\Domain\Market\Crates;
use App\Domain\Run\Rarity;
use App\Domain\Run\Surface;
use App\Support\Locale;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

function starterRulesPath(): string
{
    return config('ride.content_path').'/rules/starter.json';
}

/** @return array<string, mixed> */
function starterRules(): array
{
    return json_decode((string) file_get_contents(starterRulesPath()), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Errors of the rules schema for some data, empty when it is valid.
 *
 * @param  array<string, mixed>  $rules
 * @return array<string, list<string>>
 */
function rulesSchemaErrors(array $rules): array
{
    $validator = new Validator;
    $validator->resolver()?->registerPrefix('https://rideidle.local/schema/', config('ride.content_path').'/schema');

    // JSON_FORCE_OBJECT keeps the empty objects of the rules (stat_ppm) objects, and it makes the one list of them an object too.
    $data = json_decode(json_encode($rules, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT));

    if (isset($data->shop->parts) && is_object($data->shop->parts)) {
        $data->shop->parts = array_values((array) $data->shop->parts);
    }

    if (isset($data->roads) && is_object($data->roads)) {
        $data->roads = array_values((array) $data->roads);
    }

    if (isset($data->player->early_xp) && is_object($data->player->early_xp)) {
        $data->player->early_xp = array_values((array) $data->player->early_xp);
    }

    if (isset($data->market->exchange->defects) && is_object($data->market->exchange->defects)) {
        $data->market->exchange->defects = array_values(array_map(function (object $band): object {
            $band->weights = array_values((array) $band->weights);

            return $band;
        }, (array) $data->market->exchange->defects));
    }

    $outcome = $validator->validate($data, 'https://rideidle.local/schema/rules.schema.json');

    return $outcome->isValid() ? [] : (new ErrorFormatter)->formatKeyed($outcome->error());
}

it('matches its schema', function () {
    expect(rulesSchemaErrors(starterRules()))->toBe([]);
});

it('is refused by the schema when it asks for a statistic that does not exist', function () {
    $rules = starterRules();
    $rules['tracks']['zlomowo-street']['demand'] = ['speed' => 100];

    expect(rulesSchemaErrors($rules))->not->toBe([]);
});

it('is refused by the schema when a price is not a whole number of cents', function () {
    $rules = starterRules();
    $rules['fuel']['price_cents_per_liter'] = 1.5;

    expect(rulesSchemaErrors($rules))->not->toBe([]);
});

it('gives every track demands that add up to 100 percent and a reference for each', function () {
    $problems = [];

    foreach (starterRules()['tracks'] as $slug => $track) {
        if (array_sum($track['demand']) !== 100) {
            $problems[] = "{$slug}: the demands add up to ".array_sum($track['demand']);
        }

        foreach (array_diff(array_keys($track['demand']), array_keys($track['reference'])) as $stat) {
            $problems[] = "{$slug}: no reference for {$stat}";
        }
    }

    expect($problems)->toBe([]);
});

it('gives every region weather chances that add up to 1 000 000 and that have an effect', function () {
    $rules = starterRules();
    $problems = [];

    foreach ($rules['regions'] as $slug => $region) {
        if (array_sum($region['weather_ppm']) !== 1_000_000) {
            $problems[] = "{$slug}: the chances add up to ".array_sum($region['weather_ppm']);
        }

        foreach (array_diff(array_keys($region['weather_ppm']), array_keys($rules['weather'])) as $weather) {
            $problems[] = "{$slug}: no effect for {$weather}";
        }
    }

    expect($problems)->toBe([]);
});

it('puts every track in a known region', function () {
    $rules = starterRules();
    $unknown = [];

    foreach ($rules['tracks'] as $slug => $track) {
        if (! isset($rules['regions'][$track['region']])) {
            $unknown[] = "{$slug}: {$track['region']}";
        }
    }

    expect($unknown)->toBe([]);
});

it('names every track in all five languages', function () {
    $missing = [];

    foreach (starterRules()['tracks'] as $slug => $track) {
        foreach (array_diff(Locale::supported(), array_keys($track['name'])) as $locale) {
            $missing[] = "{$slug}: {$locale}";
        }
    }

    expect($missing)->toBe([]);
});

it('names stock tires that exist in the core pack', function () {
    $tires = json_decode((string) file_get_contents(config('ride.content_path').'/packs/core/parts/'.starterRules()['stock_tires'].'.json'), true);

    expect($tires['slot'])->toBe('tires')
        ->and($tires['stats'])->toHaveKeys(['grip', 'handling', 'braking']);
});

it('is refused by the schema when the tow is missing or has no delay', function () {
    $rules = starterRules();
    unset($rules['tow']);
    expect(rulesSchemaErrors($rules))->not->toBe([]);

    $rules = starterRules();
    $rules['tow']['delay_ms'] = 0;
    expect(rulesSchemaErrors($rules))->not->toBe([]);
});

it('makes waiting for a tow cost more than the repair and the fuel it saves', function () {
    $rules = starterRules();
    $problems = [];

    foreach ($rules['tracks'] as $slug => $track) {
        // What the car would have earned in the time it waits, against what the driver would have paid instead.
        $lostCents = $rules['tow']['delay_ms'] / $track['base_lap_ms'] * $track['reward_cents_per_lap'];
        $litersOfFuel = (int) ceil($rules['tow']['fuel_laps'] * $track['fuel_per_lap_ml'] / 1000);
        $savedCents = $rules['repair']['cost_cents'] + $litersOfFuel * $rules['fuel']['price_cents_per_liter'];

        if ($lostCents <= $savedCents) {
            $problems[] = "{$slug}: waiting costs {$lostCents} cents of earnings but the tow saves {$savedCents}, so it would be a way to avoid paying";
        }
    }

    expect($problems)->toBe([]);
});

it('is refused by the schema when the ceiling of a statistic is missing', function () {
    $rules = starterRules();
    unset($rules['car']['stat_ceiling']['grip']);

    expect(rulesSchemaErrors($rules))->not->toBe([]);
});

it('keeps every wreck a driver can start with at no more than half of the ceiling of any statistic', function () {
    $inputs = app(RunInputsFactory::class);
    $ceilings = Rules::fromArray(starterRules())->statCeilings();
    $problems = [];

    foreach (array_keys(app(Catalog::class)->startingVehicles()) as $slug) {
        foreach ($inputs->stats($slug)->stats as $stat => $value) {
            if ($value > $ceilings[$stat] / 2) {
                $problems[] = "{$slug}: {$stat} is {$value} of a ceiling of {$ceilings[$stat]}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('is refused by the schema when a car has no experience per lap or no highest level', function () {
    $rules = starterRules();
    unset($rules['car']['xp_per_lap']);
    expect(rulesSchemaErrors($rules))->not->toBe([]);

    $rules = starterRules();
    unset($rules['car']['max_level']);
    expect(rulesSchemaErrors($rules))->not->toBe([]);
});

it('makes every level of a car take more experience than the one before it, and the third band 600 as the garage board has it', function () {
    $rules = Rules::fromArray(starterRules());

    expect($rules->carXpToReach(1))->toBe(0)
        ->and($rules->carXpToReach(2))->toBe(200)
        ->and($rules->carXpToReach(3))->toBe(600)
        // The board of the garage shows 410 of 600 at level 3: the band from level 3 to 4 is 600.
        ->and($rules->carXpToReach(4) - $rules->carXpToReach(3))->toBe(600);

    $bands = [];

    for ($level = 1; $level < $rules->carMaxLevel(); $level++) {
        $bands[] = $rules->carXpToReach($level + 1) - $rules->carXpToReach($level);
    }

    expect($bands)->toBe(collect($bands)->sort()->values()->all())
        ->and(count(array_unique($bands)))->toBe(count($bands));
});

it('finds the level of a car by its experience, from the start to the highest level', function () {
    $rules = Rules::fromArray(starterRules());

    expect($rules->carLevelAt(0))->toBe(1)
        ->and($rules->carLevelAt(199))->toBe(1)
        ->and($rules->carLevelAt(200))->toBe(2)
        ->and($rules->carLevelAt(599))->toBe(2)
        ->and($rules->carLevelAt(600))->toBe(3)
        ->and($rules->carLevelAt(1_010))->toBe(3)
        ->and($rules->carLevelAt(1_200))->toBe(4)
        // Nothing takes a car past its highest level.
        ->and($rules->carLevelAt(PHP_INT_MAX))->toBe($rules->carMaxLevel());
});

it('lets a car rise high enough to mount a part of the highest tier of the pack', function () {
    $rules = Rules::fromArray(starterRules());
    $highest = 0;

    foreach (glob(config('ride.content_path').'/packs/core/parts/*.json') as $file) {
        $highest = max($highest, json_decode((string) file_get_contents($file), true)['tier']);
    }

    // The anchors of the economy go up to tier 10, which a car mounts from level 27.
    expect($rules->carMaxLevel())->toBeGreaterThanOrEqual($rules->requiredCarLevel(10))
        ->and($rules->carMaxLevel())->toBeGreaterThanOrEqual($rules->requiredCarLevel($highest));
});

it('starts a new car at the experience of its level, so that its first lap is the first step of the next one', function () {
    $rules = Rules::fromArray(starterRules());

    expect($rules->carLevelAt($rules->carXpToReach($rules->carStartLevel())))->toBe($rules->carStartLevel());
});

it('sells only parts of the core pack, none of them more than one tier above what a new car can mount', function () {
    $rules = starterRules();
    // The shop says what a car needs for a part it cannot mount yet, so the next tier is something to aim for, and nothing is out of sight.
    $maxTier = Rules::fromArray($rules)->maxMountableTier($rules['car']['start_level']) + 1;
    $problems = [];

    foreach ($rules['shop']['parts'] as $slug) {
        $path = config('ride.content_path').'/packs/core/parts/'.$slug.'.json';

        if (! is_file($path)) {
            $problems[] = "{$slug} is not in the core pack";

            continue;
        }

        $part = json_decode((string) file_get_contents($path), true);

        if ($part['tier'] > $maxTier) {
            $problems[] = "{$slug} is tier {$part['tier']}, and the shop sells up to tier {$maxTier}";
        }
    }

    expect($problems)->toBe([]);
});

it('gives the workshop a time for the slot of every part of the shop and makes taking a part off quicker', function () {
    $rules = Rules::fromArray(starterRules());

    foreach ($rules->shopParts() as $slug) {
        $part = json_decode((string) file_get_contents(config('ride.content_path').'/packs/core/parts/'.$slug.'.json'), true);

        $mounting = $rules->workshopJobMs($part['slot'], $part['tier']);

        expect($mounting)->toBeGreaterThan(0, $slug)
            ->and($rules->workshopJobMs($part['slot'], $part['tier'], removal: true))->toBeLessThan($mounting);
    }
});

it('is refused by the schema when the workshop lacks a time or the shop sells a part twice', function () {
    $rules = starterRules();
    unset($rules['workshop']['base_time_ms']);
    expect(rulesSchemaErrors($rules))->not->toBe([]);

    $rules = starterRules();
    $rules['shop']['parts'][] = $rules['shop']['parts'][0];
    expect(rulesSchemaErrors($rules))->not->toBe([]);

    $rules = starterRules();
    unset($rules['car']);
    expect(rulesSchemaErrors($rules))->not->toBe([]);
});

it('computes the time of a job of the workshop in whole milliseconds', function () {
    $rules = Rules::fromArray(starterRules());

    // Base time of the slot at tier 1, times the tier, cut by 2% for the mechanic of level 1.
    expect($rules->workshopJobMs('tires', 3))->toBe(352_800)
        ->and($rules->workshopJobMs('tires', 3, removal: true))->toBe(211_680)
        ->and($rules->workshopJobMs('suspension', 1))->toBe(166_600)
        ->and($rules->mechanic())->toBe(['level' => 1, 'time_cut_ppm' => 20_000])
        ->and($rules->requiredCarLevel(1))->toBe(0)
        ->and($rules->requiredCarLevel(2))->toBe(3)
        ->and($rules->requiredCarLevel(3))->toBe(6)
        ->and(fn () => $rules->workshopJobMs('wings', 1))->toThrow(InvalidArgumentException::class);
});

it('has no kind of tires that is best on every surface: slick wins dry asphalt, rain the wet, off-road the gravel and the mud, winter the snow', function () {
    $rules = Rules::fromArray(starterRules());
    $best = [];

    foreach (Surface::ALL as $surface) {
        $kinds = $rules->tireKinds();
        usort($kinds, fn (string $a, string $b): int => $rules->tireGripPpm($b, $surface) <=> $rules->tireGripPpm($a, $surface));

        $best[$surface] = $kinds[0];
    }

    expect($best)->toBe([
        Surface::ASPHALT_DRY => 'slick',
        Surface::ASPHALT_WET => 'rain',
        Surface::GRAVEL_DRY => 'offroad',
        Surface::MUD => 'offroad',
        Surface::SNOW => 'winter',
    ]);
});

it('lets every tire of the pack use a kind that the matrix has, and every weather say what it does to the ground', function () {
    $rules = starterRules();
    $problems = [];

    foreach (glob(config('ride.content_path').'/packs/core/parts/*.json') as $file) {
        $part = json_decode((string) file_get_contents($file), true);

        if ($part['slot'] === 'tires' && ! isset($rules['tire_matrix'][$part['tire_kind'] ?? ''])) {
            $problems[] = "{$part['slug']}: the matrix has no kind ".($part['tire_kind'] ?? '(none)');
        }
    }

    foreach ($rules['weather'] as $weather => $effect) {
        if (! in_array($effect['ground'], ['dry', 'wet', 'snow'], true)) {
            $problems[] = "{$weather}: unknown ground {$effect['ground']}";
        }
    }

    expect($problems)->toBe([]);
});

it('opens the starting track at level 1, the others from a level above it, and gives each a surface and a region', function () {
    $rules = starterRules();
    $first = array_key_first($rules['tracks']);

    expect($rules['tracks'][$first]['min_player_level'])->toBe(1);

    foreach ($rules['tracks'] as $slug => $track) {
        if ($slug !== $first) {
            expect($track['min_player_level'])->toBeGreaterThan(1, $slug);
        }

        expect($track['surface'])->toBeIn(['asphalt', 'gravel'], $slug)
            ->and($rules['regions'])->toHaveKey($track['region']);
    }
});

it('puts a track on every surface a weather of its region can make of it', function () {
    $rules = Rules::fromArray(starterRules());
    $problems = [];

    foreach ($rules->tracks() as $slug => $track) {
        foreach (array_keys($rules->region($track['region'])['weather_ppm']) as $weather) {
            try {
                $surface = Surface::of($track['surface'], $rules->weather()[$weather]['ground']);

                foreach ($rules->tireKinds() as $kind) {
                    $rules->tireGripPpm($kind, $surface);
                }
            } catch (InvalidArgumentException $exception) {
                $problems[] = "{$slug}: {$exception->getMessage()}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('draws the level of a driver by the curve of the economy ruleset, which the web draws too', function () {
    $rules = Rules::fromArray(starterRules());
    $economy = EconomyRules::fromArray(economyData());

    foreach (range(2, 30) as $level) {
        expect($rules->playerXpToReach($level))->toBe((int) round($economy->xpToReach($level)));
    }

    // Level 2 starts at 60 XP (three laps of 20), as the bar of the HUD says, and level 10 at 12 800 (640 laps).
    expect($rules->playerXpToReach(1))->toBe(0)
        ->and($rules->playerXpToReach(2))->toBe(60)
        ->and($rules->playerXpToReach(5))->toBe(800)
        ->and($rules->playerXpToReach(10))->toBe(12_800)
        ->and($rules->playerXpToReach(15))->toBe(227_481)
        ->and($rules->playerXpToReach(20))->toBe(442_162)
        ->and($rules->playerLevelAt(0))->toBe(1)
        ->and($rules->playerLevelAt(59))->toBe(1)
        ->and($rules->playerLevelAt(60))->toBe(2)
        ->and($rules->playerLevelAt(PHP_INT_MAX))->toBe(30);
});

it('lets a driver of the level of a track have the parts of its tier in the pack, so that the track can be driven well', function () {
    $rules = Rules::fromArray(starterRules());
    $problems = [];

    foreach ($rules->tracks() as $slug => $track) {
        // A car is thought of as the level of the track, but never lower than the level a new car starts with.
        $tier = $rules->maxMountableTier(max($track['min_player_level'], $rules->carStartLevel()));
        $surface = Surface::of($track['surface'], 'dry');
        $tires = [];

        foreach (glob(config('ride.content_path').'/packs/core/parts/*.json') as $file) {
            $part = json_decode((string) file_get_contents($file), true);

            if ($part['slot'] === 'tires' && $part['tier'] <= $tier) {
                $tires[] = $part['tire_kind'];
            }
        }

        if (! in_array($surface === Surface::GRAVEL_DRY ? 'offroad' : 'street', $tires, true)) {
            $problems[] = "{$slug}: no tires of the right kind up to tier {$tier}";
        }
    }

    expect($problems)->toBe([]);
});

it('says for every track whether it is lit, starts a new driver on a track that is, and makes the night longer, never shorter', function () {
    $rules = starterRules();

    expect($rules['tracks'][array_key_first($rules['tracks'])]['lit'])->toBeTrue()
        ->and($rules['clock']['night_lap_ppm'])->toBeGreaterThanOrEqual(1_000_000);

    foreach ($rules['tracks'] as $slug => $track) {
        expect($track['lit'])->toBeBool($slug);
    }

    $unlit = $rules;
    unset($unlit['tracks']['zlomowo-street']['lit']);
    $noClock = $rules;
    unset($noClock['clock']);

    expect(rulesSchemaErrors($unlit))->not->toBe([])
        ->and(rulesSchemaErrors($noClock))->not->toBe([]);
});

it('puts every city on the map with a name in all five languages and a track, and every road between cities that exist', function () {
    $rules = starterRules();
    $problems = [];

    foreach ($rules['regions'] as $slug => $region) {
        foreach (array_diff(Locale::supported(), array_keys($region['name'])) as $locale) {
            $problems[] = "{$slug}: no name in {$locale}";
        }

        if (Rules::fromArray($rules)->tracksOf($slug) === []) {
            $problems[] = "{$slug}: no track";
        }
    }

    foreach ($rules['roads'] as $road) {
        foreach (['from', 'to'] as $end) {
            if (! isset($rules['regions'][$road[$end]])) {
                $problems[] = "a road leads {$end} {$road[$end]}, which is not a city";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('multiplies the statistics of a rarer part by more, and those of a common part by nothing', function () {
    $rules = Rules::fromArray(starterRules());
    $multipliers = array_map(fn (Rarity $rarity): float => $rules->scaledStats(['grip' => 100], $rarity)['grip'], Rarity::cases());

    expect($multipliers)->toBe([100.0, 110.0, 122.0, 138.0, 160.0]);
});

it('scales what a part adds, only the gain of the power, and not a limit', function () {
    $rules = Rules::fromArray(starterRules());
    $stats = ['grip' => 100, 'handling' => 90, 'braking' => 105, 'clearance' => 25, 'power_mult' => 1.02, 'top_speed_cap_kmh' => 190, 'fade_resistance' => 100];

    expect($rules->scaledStats($stats, Rarity::Legendary))->toBe([
        'grip' => 160.0, 'handling' => 144.0, 'braking' => 168.0, 'clearance' => 40.0, 'power_mult' => 1.032, 'top_speed_cap_kmh' => 190, 'fade_resistance' => 100,
    ])->and($rules->scaledStats($stats, Rarity::Common))->toBe($stats);
});

it('has crates whose rarer parts are rarer, whose guarantees can be met, and whose kinds draw from a group that exists', function () {
    $crates = starterRules()['shop']['crates'];

    foreach ($crates['odds'] as $group => $weights) {
        $weights = array_values($weights);

        // The premium crates have more uncommon parts than common ones (the board), but from the rare ones on every rarity is rarer than the one below.
        expect($weights[2])->toBeGreaterThan($weights[3])
            ->and($weights[3])->toBeGreaterThan($weights[4])
            ->and($weights[4])->toBeGreaterThan(0);

        $floor = Rarity::fromKey($crates['guarantee'][$group]['min_rarity']);
        $above = array_sum(array_slice($weights, $floor->value - 1));

        expect($above)->toBeGreaterThan(0)
            ->and($crates['guarantee'][$group]['legendary_every'])->toBeGreaterThan($crates['guarantee'][$group]['every']);
    }

    foreach ($crates['kinds'] as $key => $kind) {
        expect($crates['odds'])->toHaveKey($kind['group']);
    }
});

it('opens the crates of the shop from level 1, and never asks for a level beyond the one of the last tier of the shop', function () {
    $rules = Rules::fromArray(starterRules());
    $crates = new Crates($rules->shopCrates());
    $top = 0;

    foreach ($rules->shopParts() as $slug) {
        $top = max($top, json_decode((string) file_get_contents(config('ride.content_path').'/packs/core/parts/'.$slug.'.json'), true)['tier']);
    }

    expect($crates->playerLevelOfTier(1))->toBe(1);

    foreach ($crates->kinds() as $key => $kind) {
        expect($kind['min_player_level'])->toBeLessThanOrEqual($crates->playerLevelOfTier($top) + 3);
    }
});

it('is refused by the schema when the crates lack odds, a guarantee is not a rarity, or a kind draws from no group', function () {
    $rules = starterRules();
    unset($rules['shop']['crates']['odds']);
    expect(rulesSchemaErrors($rules))->not->toBe([]);

    $rules = starterRules();
    $rules['shop']['crates']['guarantee']['standard']['min_rarity'] = 'mythic';
    expect(rulesSchemaErrors($rules))->not->toBe([]);

    $rules = starterRules();
    $rules['shop']['crates']['kinds']['slot']['group'] = 'free';
    expect(rulesSchemaErrors($rules))->not->toBe([]);

    $rules = starterRules();
    unset($rules['shop']['rarity_stat_ppm']['legendary']);
    expect(rulesSchemaErrors($rules))->not->toBe([]);

    expect(rulesSchemaErrors(starterRules()))->toBe([]);
});
