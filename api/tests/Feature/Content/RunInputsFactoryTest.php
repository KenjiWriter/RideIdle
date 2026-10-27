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
    // The Bizon is a rear wheel drive car and the Kuna a four wheel drive one, so they are quicker than the front wheel drive Rdzak of the same power by what
    // their drives give (the Bizon burns 5% more for it, the Kuna 12% more).
    'the Bizon M-2 is faster for all its weight, and thirstier' => ['bizon-m-2', ['dry' => 69_004, 'cloudy' => 69_004, 'rain' => 79_094, 'downpour' => 81_103, 'fog' => 70_030], 2_220, 60_000],
    'the Kuna 4x4 is slower' => ['kuna-4x4', ['dry' => 86_599, 'cloudy' => 86_599, 'rain' => 95_147, 'downpour' => 98_399, 'fog' => 87_854], 1_674, 70_000],
]);

it('rounds the reward of wet laps like the settlement vector W5, and the XP of a lap to the whole', function () {
    $config = frozenConfig('rdzak-gt-4');

    expect($config['lap_cash_cents'])->toBe(['dry' => 1_550, 'cloudy' => 1_550, 'rain' => 1_442, 'downpour' => 1_442, 'fog' => 1_550])
        // A lap gives 20 XP, and 93% of 20 is 18.6: rounded, the rain takes one from it.
        ->and($config['lap_xp'])->toBe(['dry' => 20, 'cloudy' => 20, 'rain' => 19, 'downpour' => 19, 'fog' => 20]);
});

// The reward of the Autodrom is $26.50 a lap, and the rules pay a car by its tier: 1.00 up to tier 4, then 1.09, 1.10, 1.35, 1.47 and 1.70. The cars are the
// quickest of their tiers on the track (the bots drive around them), so they are in the middle of the field and the place among the bots pays them as it is.
it('pays a lap by the tier of the car, so that the price of a car is what the income of the driver follows', function (string $car, int $dry, int $rain) {
    $config = frozenConfig($car, 'autodrom-circuit');

    expect($config['lap_cash_cents']['dry'])->toBe($dry)
        // The weather takes its share of what the car earns, not of what the track pays.
        ->and($config['lap_cash_cents']['rain'])->toBe($rain);
})->with([
    'tier 2 gets the reward of the track' => ['rdzak-gt-4', 2_650, 2_465],
    'tier 4 gets the reward of the track' => ['kornet-v6', 2_650, 2_465],
    'tier 5 gets 1.09' => ['bizon-v8', 2_889, 2_687],
    'tier 6 gets 1.10' => ['serwal-gt', 2_915, 2_711],
    'tier 7 gets 1.35' => ['kornet-gt', 3_578, 3_328],
    'tier 8 gets 1.47' => ['serwal-v10', 3_896, 3_623],
    'tier 10 gets 1.70' => ['orzel-v12', 4_505, 4_190],
]);

// The Zlomowo track pays 20 XP for a lap of 75 s, the Autodrom 20 XP for 80 s, and a car that is faster than the base lap gets the share of it.
it('pays the experience of the base lap to a car that laps the track slower, and less to one that laps it faster', function (string $car, string $track, int $dry, int $rain) {
    $config = frozenConfig($car, $track);

    expect($config['lap_xp']['dry'])->toBe($dry)
        ->and($config['lap_xp']['rain'])->toBe($rain);
})->with([
    'the starter Rdzak laps the first track in the base time' => ['rdzak-gt-4', 'zlomowo-street', 20, 19],
    'the Bizon M-2 is 8% faster: 18.4' => ['bizon-m-2', 'zlomowo-street', 18, 17],
    'the Kuna 4x4 is slower and gets the whole, not more' => ['kuna-4x4', 'zlomowo-street', 20, 19],
    'the Rdzak is slower than the base lap on the Autodrom and gets the whole' => ['rdzak-gt-4', 'autodrom-circuit', 20, 19],
    'the Kornet R6 laps the Autodrom in 72.7 s: 18.2' => ['kornet-r6', 'autodrom-circuit', 18, 17],
    'the Serwal GT in 67.6 s: 16.9' => ['serwal-gt', 'autodrom-circuit', 17, 16],
    'the Serwal V10 in 65.2 s: 16.3' => ['serwal-v10', 'autodrom-circuit', 16, 15],
    'the Orzeł V12 in 66.5 s: 16.6' => ['orzel-v12', 'autodrom-circuit', 17, 16],
]);

it('keeps the experience an hour of every car of the pack under the ceiling of every track, give or take the rounding of a lap', function () {
    $rules = app(Rules::class);
    $problems = [];

    foreach (app(Catalog::class)->vehicles() as $car => $vehicle) {
        if ($vehicle['powertrain'] !== 'ice') {
            continue;
        }

        foreach ($rules->tracks() as $slug => $track) {
            $config = frozenConfig($car, $slug);
            $perHour = 3_600_000 / $config['lap_ms']['dry'] * $config['lap_xp']['dry'];
            $ceiling = 3_600_000 / $track['base_lap_ms'] * $track['xp_per_lap'];

            // The experience of a lap is a whole number, which lets a car a few per cent over the ceiling: the 10 XP of the drag strip are the coarsest.
            if ($perHour > $ceiling * 1.06) {
                $problems[] = sprintf('%s on %s: %.0f XP an hour against a ceiling of %.0f', $car, $slug, $perHour, $ceiling);
            }
        }
    }

    expect($problems)->toBe([]);
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
    $data['tracks']['zlomowo-street']['demand'] = ['noise' => 100];
    $data['tracks']['zlomowo-street']['reference'] = ['noise' => 60];

    $factory = new RunInputsFactory(app(Catalog::class), Rules::fromArray($data));

    expect(fn () => $factory->make('rdzak-gt-4', 'zlomowo-street'))->toThrow(InvalidArgumentException::class, 'noise');
});

describe('the statistics of a car with parts on it', function () {
    it('is the stock car when nothing is mounted', function () {
        $stock = runInputsFactory()->stats('rdzak-gt-4');

        expect($stock->stats)->toBe(['accel' => 97.0, 'top_speed' => 175.0, 'grip' => 90.0, 'handling' => 88.0, 'braking' => 90.0, 'clearance' => 20.0])
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

    it('takes the clearance from the body and averages the suspension with it, so that a lowered one costs a 4x4 what it gives a hatchback', function () {
        $sport = ['suspension' => 'sport-suspension-t1'];

        expect(runInputsFactory()->stats('rdzak-gt-4')->get('clearance'))->toBe(20.0)
            ->and(runInputsFactory()->stats('bizon-m-2')->get('clearance'))->toBe(15.0)
            ->and(runInputsFactory()->stats('kuna-4x4')->get('clearance'))->toBe(80.0)
            ->and(runInputsFactory()->stats('rdzak-gt-4', $sport)->get('clearance'))->toBe(22.5)
            ->and(runInputsFactory()->stats('kuna-4x4', $sport)->get('clearance'))->toBe(52.5);
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

    it('makes every part of the shop change something about the stock car, so that nothing is sold for nothing, but the ones that are the stock car on purpose', function () {
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

        // The radiator of the factory is what the car has, and a gearbox with no launch of its own and a limit above the 175 km/h of the Rdzak does not touch it.
        expect($useless)->toBe(['factory-cooling-t1', 'manual-5-gearbox-t2', 'automatic-8-gearbox-t6']);
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
        file_put_contents("{$directory}/vehicles/toy.json", json_encode(['slug' => 'toy', 'drivetrain' => 'fwd', 'powertrain' => 'ice', 'power_kw' => 97, 'mass_kg' => 1000, 'top_speed_kmh' => 175, 'clearance' => 20, 'tank_l' => 45, 'sources' => ['start']]));
        file_put_contents("{$directory}/parts/bare-tires.json", json_encode(['slug' => 'bare-tires', 'slot' => 'tires', 'tier' => 1, 'stats' => ['grip' => 90, 'handling' => 88, 'braking' => 90]]));

        try {
            $factory = new RunInputsFactory(new Catalog($directory), Rules::fromConfig());

            expect(fn () => $factory->make('toy', 'zlomowo-street', ['tires' => 'bare-tires']))->toThrow(InvalidArgumentException::class, 'no kind');
        } finally {
            File::deleteDirectory($directory);
        }
    });
});

describe('the tracks of the world', function () {
    /** @param  array<string, string>  $mounted */
    function lapsOn(string $track, string $car, array $mounted = []): array
    {
        return (new RunFreezer)->freeze(runInputsFactory()->make($car, $track, $mounted), 'seed', 0)->config['lap_ms'];
    }

    // The reference of a track is the stats of the build it is made for, so that build runs the base lap (within the rounding of the numbers). The references
    // were made for the cars as they were before the drives, so it is the reference drive that is meant: the Kuna and the Bizon are quicker than the base lap
    // by what their four wheel drive and rear wheel drive give (DrivetrainTest).
    it('lets the build that a track is made for drive its base lap', function (string $track, string $car, array $mounted, int $baseLapMs) {
        $factory = new RunInputsFactory(app(Catalog::class), Rules::fromArray(rulesWithoutDrives()));
        $lap = (new RunFreezer)->freeze($factory->make($car, $track, $mounted), 'seed', 0)->config['lap_ms']['dry'];

        expect($lap)->toBeBetween((int) ($baseLapMs * 0.985), (int) ($baseLapMs * 1.015));
    })->with([
        'a 4x4 on off-road tires in the quarry' => ['kruszyn-quarry', 'kuna-4x4', ['tires' => 'offroad-tires-t2'], 95_000],
        'a muscle car on the drag strip' => ['suchy-pas-drag', 'bizon-m-2', [], 45_000],
        'a light hatchback with sport suspension on the switchbacks' => ['wilcza-przelecz-switchbacks', 'rdzak-gt-4', ['tires' => 'street-tires-t2', 'suspension' => 'sport-suspension-t1'], 85_000],
        'a muscle car with the brakes and the suspension on the circuit' => ['autodrom-circuit', 'bizon-m-2', ['tires' => 'street-tires-t2', 'suspension' => 'sport-suspension-t1', 'brakes' => 'disc-brakes-t2'], 80_000],
    ]);

    it('makes gravel a different track for the cars and the tires', function () {
        $quarry = lapsOn('kruszyn-quarry', 'kuna-4x4', ['tires' => 'offroad-tires-t2']);
        $onStreetTires = lapsOn('kruszyn-quarry', 'kuna-4x4');
        $hatchback = lapsOn('kruszyn-quarry', 'rdzak-gt-4');

        // Street tires slip on gravel, and a hatchback has no clearance for it: almost twice the lap of the 4x4 that was made for it.
        expect($onStreetTires['dry'])->toBeGreaterThan($quarry['dry'] * 1.3)
            ->and($hatchback['dry'])->toBeGreaterThan($quarry['dry'] * 1.8)
            // Rain turns gravel into mud, where the off-road tires lose less than the street tires do.
            ->and($quarry['rain'] / $quarry['dry'])->toBeLessThan($onStreetTires['rain'] / $onStreetTires['dry']);
    });

    it('draws the snow of a mountain region, where winter tires are the ones that grip', function () {
        $stock = lapsOn('wilcza-przelecz-switchbacks', 'rdzak-gt-4');
        $winter = lapsOn('wilcza-przelecz-switchbacks', 'rdzak-gt-4', ['tires' => 'winter-tires-t3']);

        expect($stock)->toHaveKey('snow')
            ->and($winter['snow'])->toBeLessThan($stock['snow'])
            ->and(runInputsFactory()->make('rdzak-gt-4', 'wilcza-przelecz-switchbacks')->weatherChancesPpm['snow'])->toBe(50_000);
    });

    it('freezes a lap for every weather of the region, and no weather that the region does not have', function () {
        expect(array_keys(lapsOn('suchy-pas-drag', 'bizon-m-2')))->toBe(['dry', 'cloudy', 'rain', 'fog'])
            ->and(array_keys(lapsOn('autodrom-circuit', 'bizon-m-2')))->toBe(['dry', 'cloudy', 'rain', 'downpour']);
    });
});

describe('the mass of the car', function () {
    it('leaves a car of the reference mass alone, and makes a heavy one handle and brake worse and a light one better', function () {
        $rules = Rules::fromConfig();

        expect($rules->massFactor(1_000))->toBe(1.0)
            ->and($rules->massFactor(1_480))->toBeLessThan(1.0)
            ->and($rules->massFactor(800))->toBeGreaterThan(1.0)
            ->and($rules->massFactor(1_480))->toEqualWithDelta((1000 / 1480) ** 0.6, 1e-12);
    });

    it('multiplies the handling and the braking of the body by it, after the suspension and the brakes were averaged in', function () {
        $factor = (1000 / 1480) ** 0.6;
        // The Bizon has a rear wheel drive, which handles 1.05 / 0.97 better than the front wheel drive of the reference, with 0.995 for its own weight.
        $drive = 1.05 / 0.97 * 0.995;
        $stock = runInputsFactory()->stats('bizon-m-2')->stats;
        $tuned = runInputsFactory()->stats('bizon-m-2', ['suspension' => 'sport-suspension-t1', 'brakes' => 'disc-brakes-t2'])->stats;

        expect($stock['handling'])->toEqualWithDelta(88 * $factor * $drive, 1e-9)
            ->and($stock['braking'])->toEqualWithDelta(90 * $factor, 1e-9)
            ->and($tuned['handling'])->toEqualWithDelta(94 * $factor * $drive, 1e-9)
            ->and($tuned['braking'])->toEqualWithDelta(97.5 * $factor, 1e-9)
            // What the mass has nothing to do with is not touched: the grip of the tires, the power per tonne, the clearance.
            ->and($stock['grip'])->toBe(90.0)
            ->and($stock['clearance'])->toBe(15.0);
    });
});

describe('the night and the lights of a track', function () {
    it('freezes a longer night lap only for the tracks that are not lit, and the same all day for the ones that are', function (string $track, int $nightLapPpm) {
        expect(frozenConfig('rdzak-gt-4', $track)['night_lap_ppm'])->toBe($nightLapPpm);
    })->with([
        'the street sprint has lights' => ['zlomowo-street', 1_000_000],
        'the quarry has none' => ['kruszyn-quarry', 1_030_000],
        'the drag strip has none' => ['suchy-pas-drag', 1_030_000],
        'the pass has none' => ['wilcza-przelecz-switchbacks', 1_030_000],
        'the autodrome has lights' => ['autodrom-circuit', 1_000_000],
    ]);
});

it('breaks down more the worse the condition of the car is, and as the rules say for a perfect one', function () {
    $chance = fn (int $conditionPpm): int => runInputsFactory()->make('rdzak-gt-4', 'zlomowo-street', [], $conditionPpm)->breakChancePerLapPpm;

    // 1 + 9 * (1 - condition) times the 3 000 ppm of the rules: a car in no condition at all breaks down ten times as often.
    expect($chance(1_000_000))->toBe(3_000)
        ->and($chance(500_000))->toBe(16_500)
        ->and($chance(0))->toBe(30_000)
        ->and($chance(300_000))->toBe(21_900);
});

it('does not take a condition over perfect or under nothing for more than it is', function () {
    expect(app(Rules::class)->breakChancePerLapPpmFor(2_000_000))->toBe(3_000)
        ->and(app(Rules::class)->breakChancePerLapPpmFor(-5))->toBe(30_000);
});

describe('the rarity of a part', function () {
    it('multiplies what a part adds to a car by its rarity, so that the same tires grip better the rarer they are', function () {
        $grip = fn (int $rarity): array => runInputsFactory()->stats('rdzak-gt-4', ['tires' => ['slug' => 'street-tires-t2', 'rarity' => $rarity]])->stats;

        // The street tires of tier 2 give 100, 98 and 100; the Rdzak GT-4 has the mass of reference, so the factor of the mass is 1.
        expect($grip(1)['grip'])->toBe(100.0)
            ->and($grip(2)['grip'])->toEqualWithDelta(110.0, 1e-9)
            ->and($grip(3)['grip'])->toEqualWithDelta(122.0, 1e-9)
            ->and($grip(4)['grip'])->toEqualWithDelta(138.0, 1e-9)
            ->and($grip(5)['grip'])->toEqualWithDelta(160.0, 1e-9)
            ->and($grip(4)['handling'])->toEqualWithDelta(98 * 1.38, 1e-9)
            ->and($grip(4)['braking'])->toEqualWithDelta(138.0, 1e-9);
    });

    it('gives the same statistics for a part that is given by its slug as for one of the common rarity', function () {
        $plain = runInputsFactory()->stats('rdzak-gt-4', ['tires' => 'street-tires-t2', 'exhaust' => 'sport-exhaust-t2']);
        $common = runInputsFactory()->stats('rdzak-gt-4', ['tires' => ['slug' => 'street-tires-t2', 'rarity' => 1], 'exhaust' => ['slug' => 'sport-exhaust-t2', 'rarity' => 1]]);

        expect($common->stats)->toBe($plain->stats)->and($common->fuelUseMultiplier)->toBe($plain->fuelUseMultiplier);
    });

    it('multiplies only the gain of the power of a part, not the power', function () {
        $accel = fn (int $rarity): float => runInputsFactory()->stats('rdzak-gt-4', ['exhaust' => ['slug' => 'sport-exhaust-t2', 'rarity' => $rarity]])->stats['accel'];

        // The sport exhaust gives 2% of power, and an epic one 2.76%: not 138% of the power.
        expect($accel(4) / $accel(1))->toEqualWithDelta(1.0276 / 1.02, 1e-9);
    });

    it('drives a faster lap on tires of a higher rarity', function () {
        $lap = fn (int $rarity): int => (new RunFreezer)->freeze(runInputsFactory()->make('rdzak-gt-4', 'zlomowo-street', ['tires' => ['slug' => 'street-tires-t2', 'rarity' => $rarity]]), 'seed', 0)->config['lap_ms']['dry'];

        expect($lap(2))->toBeLessThan($lap(1))->and($lap(3))->toBeLessThan($lap(2))->and($lap(4))->toBeLessThan($lap(3))->and($lap(5))->toBeLessThan($lap(4));
    });

    it('takes the tires of a rarity for the kind that picks the column of the tire matrix, as the common ones do', function () {
        $rain = fn (int $rarity): int => (new RunFreezer)->freeze(runInputsFactory()->make('rdzak-gt-4', 'zlomowo-street', ['tires' => ['slug' => 'rain-tires-t3', 'rarity' => $rarity]]), 'seed', 0)->config['lap_ms']['rain'];

        expect($rain(3))->toBeLessThan($rain(1));
    });

    it('refuses a rarity that does not exist', function () {
        expect(fn () => runInputsFactory()->stats('rdzak-gt-4', ['tires' => ['slug' => 'street-tires-t2', 'rarity' => 6]]))->toThrow(ValueError::class);
    });
});
