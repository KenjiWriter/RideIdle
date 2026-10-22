<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\RunFreezer;

function runInputsFactory(): RunInputsFactory
{
    return app(RunInputsFactory::class);
}

/** @return array<string, mixed> what the run is frozen into */
function frozenConfig(string $car, string $track = 'zlomowo-street'): array
{
    return (new RunFreezer())->freeze(runInputsFactory()->make($car, $track), 'seed', 0)->config;
}

// The expected numbers were computed apart from the code, from the formula of the design (Systemy I).
it('freezes the lap time of every starter wreck in every weather', function (string $car, array $lapMs, int $fuelPerLapMl, int $tankMl) {
    $config = frozenConfig($car);

    expect($config['lap_ms'])->toBe($lapMs)
        ->and($config['fuel_per_lap_ml'])->toBe($fuelPerLapMl)
        ->and(runInputsFactory()->tankMl($car))->toBe($tankMl);
})->with([
    'the stock Rdzak GT-4 drives the base lap' => ['rdzak-gt-4', ['dry' => 75_000, 'cloudy' => 75_000, 'rain' => 84_391, 'downpour' => 86_736, 'fog' => 76_049], 1_500, 45_000],
    'the Bizon M-2 is faster and thirstier' => ['bizon-m-2', ['dry' => 67_015, 'cloudy' => 67_015, 'rain' => 74_542, 'downpour' => 76_396, 'fog' => 67_986], 2_109, 60_000],
    'the Kuna 4x4 is slower' => ['kuna-4x4', ['dry' => 82_313, 'cloudy' => 82_313, 'rain' => 93_600, 'downpour' => 96_452, 'fog' => 83_454], 1_500, 70_000],
]);

it('rounds the reward and the XP of wet laps like the settlement vector W5', function () {
    $config = frozenConfig('rdzak-gt-4');

    expect($config['lap_cash_cents'])->toBe(['dry' => 1_550, 'cloudy' => 1_550, 'rain' => 1_442, 'downpour' => 1_442, 'fog' => 1_550])
        ->and($config['lap_xp'])->toBe(['dry' => 25, 'cloudy' => 25, 'rain' => 23, 'downpour' => 23, 'fog' => 25]);
});

it('takes the climate and the seed from the region of the track', function () {
    $inputs = runInputsFactory()->make('rdzak-gt-4', 'zlomowo-street');

    expect($inputs->weatherChancesPpm)->toBe(['dry' => 500_000, 'cloudy' => 250_000, 'rain' => 170_000, 'downpour' => 50_000, 'fog' => 30_000])
        ->and($inputs->climateSeed)->toBe('region-zlomowo')
        ->and($inputs->breakChancePerLapPpm)->toBe(3_000);
});

it('lists the three starter wrecks of the pack', function () {
    expect(array_keys(app(Catalog::class)->startingVehicles()))->toBe(['bizon-m-2', 'kuna-4x4', 'rdzak-gt-4']);
});

it('refuses a car or a track that does not exist', function (string $car, string $track) {
    runInputsFactory()->make($car, $track);
})->throws(InvalidArgumentException::class)->with([
    'unknown car' => ['no-such-car', 'zlomowo-street'],
    'unknown track' => ['rdzak-gt-4', 'no-such-track'],
]);

it('refuses a car that is not a combustion car', function () {
    $directory = sys_get_temp_dir().'/ride-pack-'.bin2hex(random_bytes(4));
    mkdir("{$directory}/vehicles", 0777, true);
    file_put_contents("{$directory}/vehicles/volt.json", json_encode(['slug' => 'volt', 'powertrain' => 'ev', 'sources' => ['start']]));

    try {
        $factory = new RunInputsFactory(new Catalog($directory), Rules::fromConfig());

        expect(fn () => $factory->make('volt', 'zlomowo-street'))->toThrow(InvalidArgumentException::class, 'combustion');
    } finally {
        unlink("{$directory}/vehicles/volt.json");
        rmdir("{$directory}/vehicles");
        rmdir($directory);
    }
});

it('refuses a track that asks for a statistic cars do not have yet', function () {
    $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true);
    $data['tracks']['zlomowo-street']['demand'] = ['clearance' => 100];
    $data['tracks']['zlomowo-street']['reference'] = ['clearance' => 60];

    $factory = new RunInputsFactory(app(Catalog::class), Rules::fromArray($data));

    expect(fn () => $factory->make('rdzak-gt-4', 'zlomowo-street'))->toThrow(InvalidArgumentException::class, 'clearance');
});
