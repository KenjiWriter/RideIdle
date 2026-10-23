<?php

declare(strict_types=1);

use App\Content\Rules;
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

it('sells only parts of the core pack that a new car can mount', function () {
    $rules = starterRules();
    $maxTier = Rules::fromArray($rules)->maxMountableTier($rules['car']['start_level']);
    $problems = [];

    foreach ($rules['shop']['parts'] as $slug) {
        $path = config('ride.content_path').'/packs/core/parts/'.$slug.'.json';

        if (! is_file($path)) {
            $problems[] = "{$slug} is not in the core pack";

            continue;
        }

        $part = json_decode((string) file_get_contents($path), true);

        if ($part['tier'] > $maxTier) {
            $problems[] = "{$slug} is tier {$part['tier']}, and a new car mounts up to tier {$maxTier}";
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
