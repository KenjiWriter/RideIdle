<?php

declare(strict_types=1);

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Errors of the economy schema for some data, empty when it is valid.
 *
 * @return array<string, list<string>>
 */
function economySchemaErrors(array $data): array
{
    $validator = new Validator;
    $validator->resolver()?->registerPrefix('https://rideidle.local/schema/', config('ride.content_path').'/schema');

    $outcome = $validator->validate(json_decode(json_encode($data, JSON_THROW_ON_ERROR)), 'https://rideidle.local/schema/economy.schema.json');

    return $outcome->isValid() ? [] : (new ErrorFormatter)->formatKeyed($outcome->error());
}

it('matches its schema', function () {
    expect(economySchemaErrors(economyData()))->toBe([]);
});

it('is refused by the schema when an anchor is missing', function () {
    $data = economyData();
    unset($data['anchors']['net_per_hour']['growth']);

    expect(economySchemaErrors($data))->not->toBe([]);
});

it('is refused by the schema when a rung of the ladder has no offline window', function () {
    $data = economyData();
    unset($data['anchors']['ladder'][3]['offline_window_h']);

    expect(economySchemaErrors($data))->not->toBe([]);
});

it('is refused by the schema when the knob of the rewards is turned past its range', function () {
    $data = economyData();
    $data['knobs']['reward_mult'] = 2.0;

    expect(economySchemaErrors($data))->not->toBe([]);
});

it('has a ladder that climbs: a dearer car, a higher level, a longer tank at every rung', function () {
    $ladder = economyData()['anchors']['ladder'];
    $problems = [];

    foreach ($ladder as $index => $rung) {
        if ($rung['rung'] !== 'L'.$index) {
            $problems[] = "rung {$index} is called {$rung['rung']}";
        }

        if ($index === 0) {
            continue;
        }

        $before = $ladder[$index - 1];

        foreach (['car_value', 'min_player_level', 'offline_window_h'] as $field) {
            if ($rung[$field] <= $before[$field]) {
                $problems[] = "{$rung['rung']}: {$field} does not rise";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('has the days of the design for every car after the first, and they only get later', function () {
    $data = economyData();
    $problems = [];

    foreach ($data['players'] as $key => $player) {
        $targets = $data['targets'][$key] ?? [];

        if (count($targets) !== count($data['anchors']['ladder']) - 1) {
            $problems[] = "{$key}: a target for each car after the first is needed";
        }

        if ($targets !== collect($targets)->sort()->values()->all()) {
            $problems[] = "{$key}: the days are not in order";
        }
    }

    expect($problems)->toBe([]);
});

it('gives a tier an anchor for every tier of the cars of the pack, and every slot of the parts a share', function () {
    $data = economyData();
    $slots = [];

    foreach (glob(config('ride.content_path').'/packs/core/parts/*.json') as $file) {
        $part = json_decode((string) file_get_contents($file), true);
        $slots[$part['slot']] = true;

        expect($data['anchors']['new_car_by_tier'])->toHaveKey($part['tier'] - 1);
    }

    expect(array_diff(array_keys($slots), array_keys($data['anchors']['parts']['slot_share'])))->toBe([]);
});

it('has shares of the slots that add up to the whole set', function () {
    expect(round(array_sum(economyData()['anchors']['parts']['slot_share']), 6))->toBe(1.0);
});
