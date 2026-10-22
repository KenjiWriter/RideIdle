<?php

declare(strict_types=1);

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
    $validator = new Validator();
    $validator->resolver()?->registerPrefix('https://rideidle.local/schema/', config('ride.content_path').'/schema');

    $outcome = $validator->validate(json_decode(json_encode($rules, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT)), 'https://rideidle.local/schema/rules.schema.json');

    return $outcome->isValid() ? [] : (new ErrorFormatter())->formatKeyed($outcome->error());
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
