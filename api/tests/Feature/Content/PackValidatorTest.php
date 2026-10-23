<?php

declare(strict_types=1);

use App\Content\PackValidationResult;
use App\Content\PackValidator;
use Illuminate\Support\Facades\File;

/** @var list<string> $packDirectories */
$GLOBALS['packDirectories'] = [];

afterEach(function () {
    foreach ($GLOBALS['packDirectories'] as $directory) {
        File::deleteDirectory($directory);
    }

    $GLOBALS['packDirectories'] = [];
});

/** @return array<string, mixed> */
function validVehicle(array $overrides = []): array
{
    return array_merge([
        'slug' => 'rdzak-gt-4',
        'name' => ['en' => 'Rdzak GT-4', 'pl' => 'Rdzak GT-4'],
        'class' => 'hatchback',
        'drivetrain' => 'fwd',
        'powertrain' => 'ice',
        'engine' => ['layout' => 'R4', 'displacement_l' => 1.8, 'tune' => 1.0],
        'power_kw' => 97,
        'mass_kg' => 1000,
        'top_speed_kmh' => 175,
        'clearance' => 20,
        'cargo_kg' => 120,
        'seats' => 4,
        'tank_l' => 45,
        'tier' => 2,
        'min_player_level' => 3,
        'price_cents' => 2150000,
        'sources' => ['start', 'exchange'],
    ], $overrides);
}

/** @return array<string, mixed> */
function validElectricVehicle(array $overrides = []): array
{
    $vehicle = validVehicle([
        'slug' => 'iskra-e-1',
        'name' => ['en' => 'Iskra E-1', 'pl' => 'Iskra E-1'],
        'powertrain' => 'ev',
        'engine' => ['motors' => 1],
        'power_kw' => 100,
        'tier' => 3,
        'min_player_level' => 6,
        'price_cents' => 3400000,
        'sources' => ['dealer'],
    ]);

    // An electric car has a battery instead of a fuel tank.
    unset($vehicle['tank_l']);

    return array_merge($vehicle, ['battery_kwh' => 30, 'max_charge_kw' => 50], $overrides);
}

/** @return array<string, mixed> */
function validPart(array $overrides = []): array
{
    return array_merge([
        'slug' => 'street-tires-t2',
        'name' => ['en' => 'Street tires', 'pl' => 'Opony uliczne'],
        'slot' => 'tires',
        'tire_kind' => 'street',
        'tier' => 2,
        'price_cents' => 57000,
        'stats' => ['grip' => 1.0, 'handling' => 0.9],
    ], $overrides);
}

/**
 * Writes a pack to a temporary directory. Each file is given as a path inside
 * the pack and either an array (encoded as JSON) or a raw string.
 *
 * @param  array<string, array<string, mixed>|string>  $files
 */
function makePack(array $files): string
{
    $directory = sys_get_temp_dir().'/ride-pack-'.bin2hex(random_bytes(6));
    $GLOBALS['packDirectories'][] = $directory;

    foreach ($files as $path => $content) {
        File::ensureDirectoryExists(dirname("{$directory}/{$path}"));
        File::put("{$directory}/{$path}", is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE) : $content);
    }

    return $directory;
}

function validatePack(array $files): PackValidationResult
{
    return PackValidator::fromConfig()->validate(makePack($files));
}

/** @return list<string> */
function errorsOf(PackValidationResult $result): array
{
    return array_map(static fn (array $issue) => "{$issue['file']} {$issue['pointer']}", $result->errors());
}

$manifest = ['schema' => 0, 'name' => 'test', 'version' => '2026.10.0'];

it('accepts a valid pack and counts the definitions', function () use ($manifest) {
    $result = validatePack([
        'pack.json' => $manifest,
        'vehicles/rdzak-gt-4.json' => validVehicle(),
        'vehicles/iskra-e-1.json' => validElectricVehicle(),
        'parts/street-tires-t2.json' => validPart(),
    ]);

    expect($result->errors())->toBe([])
        ->and($result->isValid())->toBeTrue()
        ->and($result->counts())->toBe(['vehicles' => 2, 'parts' => 1]);
});

it('warns about missing translations without failing', function () use ($manifest) {
    $result = validatePack(['pack.json' => $manifest, 'vehicles/rdzak-gt-4.json' => validVehicle()]);

    $pointers = array_column($result->warnings(), 'pointer');

    expect($result->isValid())->toBeTrue()
        ->and($pointers)->toEqualCanonicalizing(['/name/fr', '/name/de', '/name/pt-BR']);
});

it('has no warnings when all five languages are present', function () use ($manifest) {
    $name = ['en' => 'Rdzak', 'pl' => 'Rdzak', 'fr' => 'Rdzak', 'de' => 'Rdzak', 'pt-BR' => 'Rdzak'];

    $result = validatePack(['pack.json' => $manifest, 'vehicles/rdzak-gt-4.json' => validVehicle(['name' => $name])]);

    expect($result->warnings())->toBe([]);
});

it('requires the manifest', function () {
    expect(errorsOf(validatePack(['vehicles/rdzak-gt-4.json' => validVehicle()])))->toContain('pack.json ');
});

it('reports a missing directory', function () {
    $result = PackValidator::fromConfig()->validate(sys_get_temp_dir().'/does-not-exist-'.bin2hex(random_bytes(4)));

    expect($result->isValid())->toBeFalse();
});

it('rejects an unknown content folder', function () use ($manifest) {
    $result = validatePack(['pack.json' => $manifest, 'vehicle/x.json' => validVehicle()]);

    expect(errorsOf($result))->toContain('vehicle ');
});

it('reports invalid JSON', function () use ($manifest) {
    $result = validatePack(['pack.json' => $manifest, 'vehicles/broken.json' => '{ not json']);

    expect($result->isValid())->toBeFalse()
        ->and($result->errors()[0]['file'])->toBe('vehicles/broken.json')
        ->and($result->errors()[0]['message'])->toStartWith('Invalid JSON');
});

it('requires the file name to match the slug', function () use ($manifest) {
    $result = validatePack(['pack.json' => $manifest, 'vehicles/other-name.json' => validVehicle()]);

    expect(errorsOf($result))->toContain('vehicles/other-name.json /slug');
});

it('rejects invalid values', function (array $overrides, string $pointer) use ($manifest) {
    $result = validatePack(['pack.json' => $manifest, 'vehicles/rdzak-gt-4.json' => validVehicle($overrides)]);

    expect($result->isValid())->toBeFalse()
        ->and(implode("\n", errorsOf($result)))->toContain($pointer);
})->with([
    'English name missing' => [['name' => ['pl' => 'Rdzak']], '/name'],
    'Polish name missing' => [['name' => ['en' => 'Rdzak']], '/name'],
    'unsupported language key' => [['name' => ['en' => 'a', 'pl' => 'b', 'es' => 'c']], '/name'],
    'unknown class' => [['class' => 'spaceship'], '/class'],
    'unknown drivetrain' => [['drivetrain' => 'rwd4'], '/drivetrain'],
    'negative price' => [['price_cents' => -1], '/price_cents'],
    'price with decimals' => [['price_cents' => 100.5], '/price_cents'],
    'tier above ten' => [['tier' => 11, 'min_player_level' => 30], '/tier'],
    'slug with capital letters' => [['slug' => 'Rdzak-GT-4'], '/slug'],
    'no sources' => [['sources' => []], '/sources'],
    'unknown field' => [['nickname' => 'x'], ''],
]);

it('checks the powertrain specific fields', function (array $overrides, string $pointer) use ($manifest) {
    $vehicle = isset($overrides['__ev']) ? validElectricVehicle($overrides['__ev']) : validVehicle($overrides);
    $result = validatePack(['pack.json' => $manifest, "vehicles/{$vehicle['slug']}.json" => $vehicle]);

    expect($result->isValid())->toBeFalse()
        ->and(implode("\n", errorsOf($result)))->toContain($pointer);
})->with([
    'combustion car without a tank' => [['tank_l' => null], ''],
    'combustion car with a battery' => [['battery_kwh' => 30], ''],
    'combustion car with motors' => [['engine' => ['layout' => 'R4', 'displacement_l' => 1.8, 'tune' => 1.0, 'motors' => 1]], '/engine'],
    'electric car with a tank' => [['__ev' => ['tank_l' => 45]], ''],
    'electric car with a layout' => [['__ev' => ['engine' => ['motors' => 1, 'layout' => 'R4']]], '/engine'],
]);

it('requires the dealer level to follow the tier', function () use ($manifest) {
    $result = validatePack(['pack.json' => $manifest, 'vehicles/rdzak-gt-4.json' => validVehicle(['min_player_level' => 9])]);

    expect(errorsOf($result))->toContain('vehicles/rdzak-gt-4.json /min_player_level');
});

it('accepts level 1 for tier 1', function () use ($manifest) {
    $result = validatePack(['pack.json' => $manifest, 'vehicles/rdzak-gt-2.json' => validVehicle(['slug' => 'rdzak-gt-2', 'tier' => 1, 'min_player_level' => 1])]);

    expect($result->errors())->toBe([]);
});

it('validates parts', function (array $overrides) use ($manifest) {
    $result = validatePack(['pack.json' => $manifest, 'parts/street-tires-t2.json' => validPart($overrides)]);

    expect($result->isValid())->toBeFalse();
})->with([
    'unknown slot' => [['slot' => 'wings']],
    'no stats' => [['stats' => []]],
    'non numeric stat' => [['stats' => ['grip' => 'high']]],
    'stat name in capitals' => [['stats' => ['Grip' => 1]]],
    'unknown vehicle class in fits' => [['fits' => ['classes' => ['tank']]]],
    'unknown kind of tires' => [['tire_kind' => 'sand']],
    'a kind of tires on a part that is not a tire' => [['slot' => 'exhaust']],
]);

it('requires every tire to say which column of the tire matrix it uses', function () use ($manifest) {
    $tires = validPart();
    unset($tires['tire_kind']);

    $result = validatePack(['pack.json' => $manifest, 'parts/street-tires-t2.json' => $tires]);

    expect($result->isValid())->toBeFalse();
});

it('accepts a part that is not a tire without a kind', function () use ($manifest) {
    $exhaust = validPart(['slug' => 'sport-exhaust-t2', 'slot' => 'exhaust', 'stats' => ['power_mult' => 1.02]]);
    unset($exhaust['tire_kind']);

    $result = validatePack(['pack.json' => $manifest, 'parts/sport-exhaust-t2.json' => $exhaust]);

    expect($result->errors())->toBe([]);
});

it('rejects a manifest for a different schema version', function () {
    $result = validatePack(['pack.json' => ['schema' => 1, 'name' => 'test', 'version' => '2026.10.0']]);

    expect(errorsOf($result))->toContain('pack.json /schema');
});

it('rejects a malformed pack version', function () {
    $result = validatePack(['pack.json' => ['schema' => 0, 'name' => 'test', 'version' => 'v1']]);

    expect(errorsOf($result))->toContain('pack.json /version');
});

it('accepts an optional description and warns about its missing translations', function () use ($manifest) {
    $result = validatePack([
        'pack.json' => $manifest,
        'vehicles/rdzak-gt-4.json' => validVehicle(['description' => ['en' => 'City streets.', 'pl' => 'Ulica.']]),
    ]);

    $pointers = array_column($result->warnings(), 'pointer');

    expect($result->isValid())->toBeTrue()
        ->and($pointers)->toEqualCanonicalizing([
            '/name/fr', '/name/de', '/name/pt-BR',
            '/description/fr', '/description/de', '/description/pt-BR',
        ]);
});

it('refuses a description that lacks a required language', function () use ($manifest) {
    $result = validatePack([
        'pack.json' => $manifest,
        'vehicles/rdzak-gt-4.json' => validVehicle(['description' => ['en' => 'City streets.']]),
    ]);

    expect($result->isValid())->toBeFalse()
        ->and(errorsOf($result))->toContain('vehicles/rdzak-gt-4.json /description');
});
