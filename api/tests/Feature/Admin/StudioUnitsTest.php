<?php

declare(strict_types=1);

use App\Content\Definitions\JsonDocument;
use App\Content\Studio\Parameter;
use App\Content\Studio\ParameterCatalog;
use App\Content\Studio\Unit;

// The unit a number of the studio is shown in: by its own name (`night_lap_ppm`), and for a number that is named by what it is for (`uncommon`, `tires`, `min`) by the name of the
// object that holds it (`base_ppm`, `base_time_ms`, `condition_ppm`), so that no number that is a share is shown as a raw count of millionths.

beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
});

/** @return array<string, Parameter> every number of the rules by the pattern of its pointer */
function unitsByPattern(): array
{
    $document = JsonDocument::decode((string) repositoryContent()->raw('rules/starter'));
    $catalog = ParameterCatalog::ofTheGame();
    $found = [];

    foreach (array_keys($catalog->sections($document)) as $section) {
        foreach ($catalog->parameters($document, $section) as $parameter) {
            $found[$parameter->pattern] ??= $parameter;
        }
    }

    return $found;
}

it('shows a number that is named by what it is for in the unit of the object that holds it', function () {
    $numbers = unitsByPattern();

    expect($numbers['/workshop/merge/base_ppm/uncommon']->unit)->toBe(Unit::Percent)
        ->and($numbers['/workshop/merge/base_ppm/uncommon']->display())->toBe(80)
        ->and($numbers['/workshop/base_time_ms/*']->unit)->toBe(Unit::Seconds)
        ->and($numbers['/market/pricing/class_floor_ppm/*']->unit)->toBe(Unit::Percent)
        ->and($numbers['/market/exchange/defect_ppm/min']->unit)->toBe(Unit::Percent)
        ->and($numbers['/garage/wreck_price_cents/*']->unit)->toBe(Unit::Dollars)
        ->and($numbers['/shop/rarity_stat_ppm/legendary']->unit)->toBe(Unit::Percent);
});

it('still takes the unit of the name of a number that has one, and leaves a plain number plain', function () {
    $numbers = unitsByPattern();

    expect($numbers['/clock/night_lap_ppm']->unit)->toBe(Unit::Percent)
        ->and($numbers['/fuel/price_cents_per_liter']->unit)->toBe(Unit::Dollars)
        ->and($numbers['/market/exchange/mileage_km/min']->unit)->toBe(Unit::Plain)
        ->and($numbers['/car/stat_ceiling/accel']->unit)->toBe(Unit::Plain);
});

it('turns what a person writes back into the whole number of the document in the unit it was shown in', function () {
    $document = JsonDocument::decode((string) repositoryContent()->raw('rules/starter'));
    $catalog = ParameterCatalog::ofTheGame();
    $position = null;

    foreach ($catalog->parameters($document, 'workshop') as $at => $parameter) {
        if ($parameter->pattern === '/workshop/merge/base_ppm/uncommon') {
            $position = $at;
        }
    }

    // 65 % in the panel is 650 000 in the document.
    expect($catalog->apply($document, 'workshop', [$position => 65])['workshop']['merge']['base_ppm']['uncommon'])->toBe(650_000);
});
