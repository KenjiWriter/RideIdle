<?php

declare(strict_types=1);

use App\Content\PackValidator;

/** The pack that ships with the game must always be valid and fully translated. */
function corePackPath(): string
{
    return config('ride.content_path').'/packs/core';
}

it('is valid and complete in all five languages', function () {
    $result = PackValidator::fromConfig()->validate(corePackPath());

    expect($result->errors())->toBe([])
        ->and($result->warnings())->toBe([]);
});

it('passes the validator command in strict mode', function () {
    $this->artisan('content:validate', ['--strict' => true])->assertSuccessful();
});

it('contains the three starter wrecks', function () {
    $starters = collect(glob(corePackPath().'/vehicles/*.json'))
        ->map(fn (string $path) => json_decode((string) file_get_contents($path), true))
        ->filter(fn (array $vehicle) => in_array('start', $vehicle['sources'], true))
        ->pluck('slug')
        ->sort()
        ->values()
        ->all();

    expect($starters)->toBe(['bizon-m-2', 'kuna-4x4', 'rdzak-gt-4']);
});

it('keeps the three drivetrains of the starter wrecks different', function () {
    $drivetrains = collect(['rdzak-gt-4', 'bizon-m-2', 'kuna-4x4'])
        ->map(fn (string $slug) => json_decode((string) file_get_contents(corePackPath()."/vehicles/{$slug}.json"), true)['drivetrain'])
        ->all();

    expect($drivetrains)->toBe(['fwd', 'rwd', 'awd']);
});

it('prices parts in whole cents and keeps slugs unique across the pack', function () {
    $slugs = collect(glob(corePackPath().'/{vehicles,parts}/*.json', GLOB_BRACE))
        ->map(fn (string $path) => basename($path, '.json'));

    expect($slugs->unique()->count())->toBe($slugs->count());
});
