<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\RunFreezer;
use Illuminate\Support\Facades\File;

function runInputsFactory(): RunInputsFactory
{
    return app(RunInputsFactory::class);
}

/** @return array<string, mixed> what the run is frozen into */
function frozenConfig(string $car, string $track = 'zlomowo-street'): array
{
    return (new RunFreezer)->freeze(runInputsFactory()->make($car, $track), 'seed', 0)->config;
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

it('rounds the reward of wet laps like the settlement vector W5, and the XP of a lap to the whole', function () {
    $config = frozenConfig('rdzak-gt-4');

    expect($config['lap_cash_cents'])->toBe(['dry' => 1_550, 'cloudy' => 1_550, 'rain' => 1_442, 'downpour' => 1_442, 'fog' => 1_550])
        // A lap gives 2 XP, and 93% of 2 is 1.86: rounded, the rain takes nothing from it.
        ->and($config['lap_xp'])->toBe(['dry' => 2, 'cloudy' => 2, 'rain' => 2, 'downpour' => 2, 'fog' => 2]);
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

describe('the statistics of a car with parts on it', function () {
    it('is the stock car when nothing is mounted', function () {
        $stock = runInputsFactory()->stats('rdzak-gt-4');

        expect($stock->stats)->toBe(['accel' => 97.0, 'top_speed' => 175.0, 'grip' => 90.0, 'handling' => 88.0, 'braking' => 90.0])
            ->and(runInputsFactory()->stats('rdzak-gt-4', [])->stats)->toBe($stock->stats);
    });

    it('takes grip, handling and braking from the tires that are mounted', function () {
        $stats = runInputsFactory()->stats('rdzak-gt-4', ['tires' => 'street-tires-t2'])->stats;

        expect($stats)->toMatchArray(['grip' => 100.0, 'handling' => 98.0, 'braking' => 100.0, 'accel' => 97.0, 'top_speed' => 175.0]);
    });

    it('averages the suspension with the handling of the tires and the brakes with their braking', function () {
        $stats = runInputsFactory()->stats('rdzak-gt-4', ['suspension' => 'sport-suspension-t1', 'brakes' => 'disc-brakes-t2'])->stats;

        expect($stats['handling'])->toBe(94.0)
            ->and($stats['braking'])->toBe(97.5)
            ->and($stats['grip'])->toBe(90.0);
    });

    it('turns the power multiplier of the exhaust into acceleration, a little top speed and a thirstier car', function () {
        $stock = runInputsFactory()->stats('rdzak-gt-4');
        $tuned = runInputsFactory()->stats('rdzak-gt-4', ['exhaust' => 'sport-exhaust-t2']);

        expect($tuned->get('accel'))->toEqualWithDelta(97 * 1.02, 1e-9)
            ->and($tuned->get('top_speed'))->toEqualWithDelta(175 * 1.02 ** (1 / 3), 1e-9)
            ->and($tuned->fuelUseMultiplier)->toBeGreaterThan($stock->fuelUseMultiplier);
    });

    it('never lets the top speed go above the cap of the gearbox', function () {
        $capped = runInputsFactory()->stats('bizon-m-2', ['gearbox' => 'manual-5-gearbox-t2']);

        expect(runInputsFactory()->stats('bizon-m-2')->get('top_speed'))->toBe(200.0)
            ->and($capped->get('top_speed'))->toBe(190.0);
    });

    it('takes the size of the tank from the tank that is mounted', function () {
        expect(runInputsFactory()->tankMl('bizon-m-2'))->toBe(60_000)
            ->and(runInputsFactory()->tankMl('bizon-m-2', ['tank' => 'fuel-tank-45l-t1']))->toBe(45_000);
    });

    it('makes every part of the shop change something about the stock car, so that nothing is sold for nothing', function () {
        $stock = runInputsFactory()->stats('rdzak-gt-4');
        $catalog = app(Catalog::class);
        $rules = Rules::fromConfig();
        $useless = [];

        foreach ($rules->shopParts() as $slug) {
            $slot = $catalog->part($slug)['slot'];
            $with = runInputsFactory()->stats('rdzak-gt-4', [$slot => $slug]);

            if ($with->stats === $stock->stats && $with->fuelUseMultiplier === $stock->fuelUseMultiplier) {
                $useless[] = $slug;
            }
        }

        expect($useless)->toBe([]);
    });

    it('drives a faster lap with every part of the shop on the car than with none', function () {
        $mounted = ['tires' => 'street-tires-t2', 'suspension' => 'sport-suspension-t1', 'brakes' => 'disc-brakes-t2', 'exhaust' => 'sport-exhaust-t2'];

        $stock = (new RunFreezer)->freeze(runInputsFactory()->make('rdzak-gt-4', 'zlomowo-street'), 'seed', 0)->config;
        $tuned = (new RunFreezer)->freeze(runInputsFactory()->make('rdzak-gt-4', 'zlomowo-street', $mounted), 'seed', 0)->config;

        expect($tuned['lap_ms']['dry'])->toBeLessThan($stock['lap_ms']['dry'])
            ->and($tuned['lap_ms']['rain'])->toBeLessThan($stock['lap_ms']['rain']);
    });

    it('refuses a part that is not in the pack or sits in a slot that is not its own', function () {
        expect(fn () => runInputsFactory()->stats('rdzak-gt-4', ['exhaust' => 'no-such-part']))->toThrow(InvalidArgumentException::class, 'Unknown part')
            ->and(fn () => runInputsFactory()->stats('rdzak-gt-4', ['brakes' => 'sport-exhaust-t2']))->toThrow(InvalidArgumentException::class, 'belongs to the slot exhaust');
    });
});

describe('the tires and the surface', function () {
    /** @param  array<string, string>  $mounted */
    function freezeWith(array $mounted, string $car = 'rdzak-gt-4'): array
    {
        return (new RunFreezer)->freeze(runInputsFactory()->make($car, 'zlomowo-street', $mounted), 'seed', 0)->config;
    }

    it('multiplies grip, handling and braking by the column of the matrix that the weather makes of the track', function () {
        $stock = runInputsFactory()->make('rdzak-gt-4', 'zlomowo-street');
        $rain = runInputsFactory()->make('rdzak-gt-4', 'zlomowo-street', ['tires' => 'rain-tires-t3']);

        // Street tires, as the weather had them before the matrix: 0.85 on wet asphalt, and the downpour takes a tenth of the grip more.
        expect($stock->effectFor('dry')->multiplierFor('grip'))->toBe(1.0)
            ->and($stock->effectFor('rain')->multiplierFor('grip'))->toBe(0.85)
            ->and($stock->effectFor('downpour')->multiplierFor('grip'))->toBe(0.765)
            ->and($stock->effectFor('downpour')->multiplierFor('braking'))->toBe(0.85)
            // Rain tires: a little worse on dry asphalt, better than anything on wet.
            ->and($rain->effectFor('dry')->multiplierFor('handling'))->toBe(0.9)
            ->and($rain->effectFor('rain')->multiplierFor('handling'))->toBe(1.05)
            ->and($rain->effectFor('downpour')->multiplierFor('grip'))->toBe(0.945)
            // What does not depend on the grip of the road is not touched by the tires.
            ->and($rain->effectFor('rain')->multiplierFor('top_speed'))->toBe(1.0)
            ->and($rain->effectFor('fog')->multiplierFor('top_speed'))->toBe(0.92);
    });

    it('lets the rain tires win the wet and lose the dry against the street tires of a higher tier', function () {
        $street = freezeWith(['tires' => 'street-tires-t2']);
        $rain = freezeWith(['tires' => 'rain-tires-t3']);

        expect($rain['lap_ms']['rain'])->toBeLessThan($street['lap_ms']['rain'])
            ->and($rain['lap_ms']['downpour'])->toBeLessThan($street['lap_ms']['downpour'])
            ->and($rain['lap_ms']['dry'])->toBeGreaterThan($street['lap_ms']['dry']);
    });

    it('refuses tires that do not say how they grip', function () {
        $directory = sys_get_temp_dir().'/ride-pack-'.bin2hex(random_bytes(4));
        mkdir("{$directory}/vehicles", 0777, true);
        mkdir("{$directory}/parts", 0777, true);
        file_put_contents("{$directory}/vehicles/toy.json", json_encode(['slug' => 'toy', 'powertrain' => 'ice', 'power_kw' => 97, 'mass_kg' => 1000, 'top_speed_kmh' => 175, 'tank_l' => 45, 'sources' => ['start']]));
        file_put_contents("{$directory}/parts/bare-tires.json", json_encode(['slug' => 'bare-tires', 'slot' => 'tires', 'tier' => 1, 'stats' => ['grip' => 90, 'handling' => 88, 'braking' => 90]]));

        try {
            $factory = new RunInputsFactory(new Catalog($directory), Rules::fromConfig());

            expect(fn () => $factory->make('toy', 'zlomowo-street', ['tires' => 'bare-tires']))->toThrow(InvalidArgumentException::class, 'no kind');
        } finally {
            File::deleteDirectory($directory);
        }
    });
});
