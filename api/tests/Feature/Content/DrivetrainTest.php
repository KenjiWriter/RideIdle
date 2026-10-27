<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\RunFreezer;
use App\Domain\Settlement\Ppm;
use Illuminate\Support\Facades\File;

// The drive of the car (Katalog aut, Napęd): the numbers of the board are in the rules as they are, and every factor is taken relative to the front wheel drive,
// because the anchors of the design give the front wheel drive starter its base lap.

/**
 * A factory whose Rdzak GT-4 has this drive and this power, on the real parts and the real rules (or on these rules).
 *
 * @param  array<string, mixed>|null  $rules
 * @return array{0: RunInputsFactory, 1: string} the factory and the directory of the pack, which the caller deletes
 */
function rdzakWith(string $drivetrain, ?int $powerKw = null, ?array $rules = null): array
{
    $directory = sys_get_temp_dir().'/ride-drive-'.bin2hex(random_bytes(4));
    File::copyDirectory(config('ride.content_path').'/packs/core', $directory);

    $path = "{$directory}/vehicles/rdzak-gt-4.json";
    $vehicle = json_decode((string) file_get_contents($path), true);
    $vehicle['drivetrain'] = $drivetrain;
    $vehicle['power_kw'] = $powerKw ?? $vehicle['power_kw'];
    file_put_contents($path, json_encode($vehicle));

    return [new RunInputsFactory(new Catalog($directory), $rules === null ? Rules::fromConfig() : Rules::fromArray($rules)), $directory];
}

/**
 * @param  array<string, string>  $mounted
 * @return array<string, int> the dry, rain and other laps of the Rdzak GT-4 with this drive and these tires on this track, in ms
 */
function driveLaps(string $drivetrain, string $track, array $mounted = [], ?int $powerKw = null): array
{
    [$factory, $directory] = rdzakWith($drivetrain, $powerKw);

    try {
        return (new RunFreezer)->freeze($factory->make('rdzak-gt-4', $track, $mounted), 'drive', 0)->config['lap_ms'];
    } finally {
        File::deleteDirectory($directory);
    }
}

/** @return array<string, float> the statistics of the Rdzak GT-4 with this drive, on these rules or the real ones */
function driveStats(string $drivetrain, ?int $powerKw = null, array $mounted = [], ?array $rules = null): array
{
    [$factory, $directory] = rdzakWith($drivetrain, $powerKw, $rules);

    try {
        return $factory->stats('rdzak-gt-4', $mounted)->stats;
    } finally {
        File::deleteDirectory($directory);
    }
}

describe('the drives in the rules', function () {
    it('has the front wheel drive for the reference, so that it is multiplied by exactly nothing', function () {
        $fwd = Rules::fromConfig()->drivetrain('fwd');

        expect($fwd['start'])->toBe(1.0)
            ->and($fwd['handling'])->toBe(1.0)
            ->and($fwd['mass'])->toBe(1.0)
            ->and($fwd['fuel'])->toBe(1.0)
            ->and($fwd['service_ppm'])->toBe(Ppm::ONE)
            ->and(array_unique($fwd['traction_ppm']))->toBe(['asphalt_dry' => Ppm::ONE]);
    });

    it('takes the other drives relative to it: the ratio of the rows of the board', function () {
        $rules = Rules::fromConfig();
        $rwd = $rules->drivetrain('rwd');
        $awd = $rules->drivetrain('awd');

        expect($rwd['start'])->toEqualWithDelta(1.00 / 0.92, 1e-12)
            ->and($rwd['handling'])->toEqualWithDelta(1.05 / 0.97, 1e-12)
            ->and($rwd['mass'])->toBe(0.995)
            ->and($rwd['fuel'])->toEqualWithDelta(1.00 / 0.95, 1e-12)
            ->and($rwd['traction_ppm'])->toBe(['asphalt_dry' => 1_030_928, 'asphalt_wet' => 969_388, 'gravel_dry' => 948_454, 'mud' => 926_316, 'snow' => 867_347])
            ->and($awd['start'])->toEqualWithDelta(1.10 / 0.92, 1e-12)
            ->and($awd['mass'])->toBe(0.97)
            ->and($awd['traction_ppm'])->toBe(['asphalt_dry' => 1_061_856, 'asphalt_wet' => 1_102_041, 'gravel_dry' => 1_134_021, 'mud' => 1_178_947, 'snow' => 1_173_469])
            // A four wheel drive is more to look after: 20% more for a service.
            ->and($awd['service_ppm'])->toBe(1_200_000)
            ->and($rwd['service_ppm'])->toBe(Ppm::ONE);
    });

    it('makes another drive the reference when the rules say so, and refuses a drive it does not know', function () {
        $data = Rules::fromConfig();
        $array = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true);
        $array['drivetrain']['reference'] = 'rwd';
        $rwdFirst = Rules::fromArray($array);

        expect($rwdFirst->drivetrain('rwd')['start'])->toBe(1.0)
            ->and($rwdFirst->drivetrain('fwd')['start'])->toEqualWithDelta(0.92, 1e-12)
            ->and($data->drivetrain('fwd')['start'])->toBe(1.0)
            ->and(fn () => $data->drivetrain('rocket'))->toThrow(InvalidArgumentException::class, 'rocket');
    });

    it('takes the acceleration off a car that has more power than its drive can put on the road: (limit / power) ^ exponent', function () {
        $rules = Rules::fromConfig();

        expect($rules->drivetrainPowerFactor('fwd', 97.0))->toBe(1.0)
            ->and($rules->drivetrainPowerFactor('fwd', 130.0))->toBe(1.0)
            ->and($rules->drivetrainPowerFactor('fwd', 163.0))->toEqualWithDelta((130 / 163) ** 0.4, 1e-12)
            ->and($rules->drivetrainPowerFactor('fwd', 239.0))->toEqualWithDelta((130 / 239) ** 0.4, 1e-12)
            ->and($rules->drivetrainPowerFactor('rwd', 260.0))->toBe(1.0)
            ->and($rules->drivetrainPowerFactor('rwd', 400.0))->toEqualWithDelta((260 / 400) ** 0.25, 1e-12)
            // A four wheel drive has no limit.
            ->and($rules->drivetrainPowerFactor('awd', 900.0))->toBe(1.0);
    });

    it('charges a fifth more for the service of a four wheel drive, the least included', function () {
        $rules = Rules::fromConfig();

        expect($rules->serviceCostCents(1_550, 2_600))->toBe(119_288)
            ->and($rules->serviceCostCents(1_550, 2_600, 'fwd'))->toBe(119_288)
            ->and($rules->serviceCostCents(1_550, 2_600, 'rwd'))->toBe(119_288)
            ->and($rules->serviceCostCents(1_550, 2_600, 'awd'))->toBe(143_146)
            ->and($rules->serviceCostCents(1, 2_600, 'awd'))->toBe(2_400);
    });
});

describe('the drive of the car', function () {
    it('leaves a front wheel drive car under its limit exactly as it was before the drives', function () {
        $before = driveStats('fwd', rules: rulesWithoutDrives());

        expect(driveStats('fwd'))->toBe($before)
            ->and(driveLaps('fwd', 'zlomowo-street'))->toBe(['dry' => 75_000, 'cloudy' => 75_000, 'rain' => 84_391, 'downpour' => 86_736, 'fog' => 76_049]);
    });

    it('gives a rear wheel drive car the start, the handling and the fuel use of its row relative to the front wheel drive, and does not touch what the drive has nothing to do with', function () {
        $fwd = driveStats('fwd');
        $rwd = driveStats('rwd');
        $awd = driveStats('awd');

        expect($rwd['accel'] / $fwd['accel'])->toEqualWithDelta(1.00 / 0.92 * 0.995, 1e-12)
            ->and($rwd['handling'] / $fwd['handling'])->toEqualWithDelta(1.05 / 0.97 * 0.995, 1e-12)
            ->and($awd['accel'] / $fwd['accel'])->toEqualWithDelta(1.10 / 0.92 * 0.97, 1e-12)
            ->and($awd['handling'] / $fwd['handling'])->toEqualWithDelta(0.94 / 0.97 * 0.97, 1e-12)
            // The top speed, the grip of the tires, the braking (the traction on a surface is in the weather) and the clearance are the body's and the tires'.
            ->and($rwd['top_speed'])->toBe($fwd['top_speed'])
            ->and($awd['grip'])->toBe($fwd['grip'])
            ->and($awd['braking'])->toBe($fwd['braking'])
            ->and($awd['clearance'])->toBe($fwd['clearance']);

        [$factory, $directory] = rdzakWith('rwd');
        [$front, $frontDirectory] = rdzakWith('fwd');

        try {
            expect($factory->stats('rdzak-gt-4')->fuelUseMultiplier / $front->stats('rdzak-gt-4')->fuelUseMultiplier)->toEqualWithDelta(1.00 / 0.95, 1e-12);
        } finally {
            File::deleteDirectory($directory);
            File::deleteDirectory($frontDirectory);
        }
    });

    it('multiplies the grip, the handling and the braking by the traction of the drive on the surface of the weather, like the tires', function () {
        [$front, $frontDirectory] = rdzakWith('fwd');
        [$four, $fourDirectory] = rdzakWith('awd');

        try {
            // Zlomowo is asphalt: dry in the sun (1.03 / 0.97 of the four wheel drive against the front one), wet in the rain (1.08 / 0.98, and the stock street
            // tires grip 0.85 there).
            $dry = $four->make('rdzak-gt-4', 'zlomowo-street')->effectFor('dry');
            $wet = $four->make('rdzak-gt-4', 'zlomowo-street')->effectFor('rain');
            $frontDry = $front->make('rdzak-gt-4', 'zlomowo-street')->effectFor('dry');
            $frontWet = $front->make('rdzak-gt-4', 'zlomowo-street')->effectFor('rain');

            expect($frontDry->multiplierFor('grip'))->toBe(1.0)
                ->and($frontWet->multiplierFor('grip'))->toBe(0.85)
                ->and($dry->multiplierFor('grip'))->toBe(1.061856)
                ->and($dry->multiplierFor('braking'))->toBe(1.061856)
                ->and($dry->multiplierFor('handling'))->toBe(1.061856)
                ->and($dry->multiplierFor('top_speed'))->toBe(1.0)
                ->and($wet->multiplierFor('grip'))->toBe(0.936735)
                ->and($wet->multiplierFor('accel'))->toBe(1.0);
        } finally {
            File::deleteDirectory($frontDirectory);
            File::deleteDirectory($fourDirectory);
        }
    });

    it('lets the same build lap as the board of the catalog says on its four examples: front wheel drive and four wheel drive against rear wheel drive', function (string $track, array $mounted, string $weather, float $fwdToRwd, float $awdToRwd) {
        $fwd = driveLaps('fwd', $track, $mounted)[$weather];
        $rwd = driveLaps('rwd', $track, $mounted)[$weather];
        $awd = driveLaps('awd', $track, $mounted)[$weather];

        // The board gives the ratios to three places and our model is its table with the front wheel drive as the reference: within 0.7% of its examples.
        expect($fwd / $rwd)->toEqualWithDelta($fwdToRwd, 0.007)
            ->and($awd / $rwd)->toEqualWithDelta($awdToRwd, 0.007);
    })->with([
        'a drag strip on street tires' => ['suchy-pas-drag', ['tires' => 'street-tires-t2'], 'dry', 1.059, 0.961],
        'the Autodrom, dry, on street tires' => ['autodrom-circuit', ['tires' => 'street-tires-t2'], 'dry', 1.055, 1.004],
        'the Autodrom in the rain on all-season tires' => ['autodrom-circuit', ['tires' => 'all-season-tires-t1'], 'rain', 1.012, 0.937],
        'gravel on off-road tires' => ['kruszyn-quarry', ['tires' => 'offroad-tires-t2'], 'dry', 0.985, 0.891],
    ]);

    it('takes the acceleration off a front wheel drive car with more than 130 kW and not off a rear wheel drive one, and a four wheel drive one keeps all of it', function () {
        $neutral = driveStats('fwd', 200, rules: rulesWithoutDrives())['accel'];

        expect(driveStats('fwd', 200)['accel'])->toEqualWithDelta($neutral * (130 / 200) ** 0.4, 1e-9)
            ->and(driveStats('rwd', 200)['accel'])->toEqualWithDelta($neutral * 1.00 / 0.92 * 0.995, 1e-9)
            ->and(driveStats('rwd', 400)['accel'])->toEqualWithDelta(driveStats('fwd', 400, rules: rulesWithoutDrives())['accel'] * 1.00 / 0.92 * 0.995 * (260 / 400) ** 0.25, 1e-9)
            ->and(driveStats('awd', 400)['accel'])->toEqualWithDelta(driveStats('fwd', 400, rules: rulesWithoutDrives())['accel'] * 1.10 / 0.92 * 0.97, 1e-9);
    });

    it('counts the power the booster adds against the limit', function () {
        // 110 kW is under the limit of 130 and a Turbo Grande (x1.4) takes it to 154: the same car without one loses nothing, with one it loses the limit of 154 kW.
        $plain = driveStats('fwd', 110)['accel'];
        $neutralPlain = driveStats('fwd', 110, rules: rulesWithoutDrives())['accel'];
        $boosted = driveStats('fwd', 110, ['boost' => 'turbo-grande-t6']);
        $neutralBoosted = driveStats('fwd', 110, ['boost' => 'turbo-grande-t6'], rulesWithoutDrives());

        expect($plain)->toBe($neutralPlain)
            ->and($boosted['accel'])->toEqualWithDelta($neutralBoosted['accel'] * (130 / 154) ** 0.4, 1e-9)
            // The top speed and the fuel are not the business of the limit.
            ->and($boosted['top_speed'])->toBe($neutralBoosted['top_speed']);
    });
});
