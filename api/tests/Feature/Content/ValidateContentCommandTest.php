<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function commandPack(array $files): string
{
    $directory = sys_get_temp_dir().'/ride-command-pack-'.bin2hex(random_bytes(6));

    foreach ($files as $path => $content) {
        File::ensureDirectoryExists(dirname("{$directory}/{$path}"));
        File::put("{$directory}/{$path}", json_encode($content, JSON_UNESCAPED_UNICODE));
    }

    return $directory;
}

$manifest = ['schema' => 0, 'name' => 'test', 'version' => '2026.10.0'];

$vehicle = [
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
    'sources' => ['start'],
];

it('succeeds for a valid pack', function () use ($manifest, $vehicle) {
    $path = commandPack(['pack.json' => $manifest, 'vehicles/rdzak-gt-4.json' => $vehicle]);

    $this->artisan('content:validate', ['path' => $path])
        ->expectsOutputToContain('Pack is valid (1 vehicles)')
        ->assertSuccessful();

    File::deleteDirectory($path);
});

it('fails and lists the problems for an invalid pack', function () use ($manifest, $vehicle) {
    $path = commandPack(['pack.json' => $manifest, 'vehicles/rdzak-gt-4.json' => array_merge($vehicle, ['tier' => 11])]);

    $this->artisan('content:validate', ['path' => $path])
        ->expectsOutputToContain('vehicles/rdzak-gt-4.json /tier')
        ->assertFailed();

    File::deleteDirectory($path);
});

it('treats warnings as errors with --strict', function () use ($manifest, $vehicle) {
    $path = commandPack(['pack.json' => $manifest, 'vehicles/rdzak-gt-4.json' => $vehicle]);

    $this->artisan('content:validate', ['path' => $path])->assertSuccessful();
    $this->artisan('content:validate', ['path' => $path, '--strict' => true])->assertFailed();

    File::deleteDirectory($path);
});
