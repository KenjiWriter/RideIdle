<?php

declare(strict_types=1);

use App\Content\RunInputsFactory;
use App\Domain\Run\RunFreezer;
use App\Models\Car;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\SettlementEvent;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CarWear;
use App\Services\RunService;
use Illuminate\Support\Carbon;

// The Rdzak GT-4 on the street track of Zlomowo (2.6 km a lap), tires of tier 2 on it that wear 32 500 billionths of their life a lap (25 000 a kilometre:
// 40 000 km of life), in stages of 5% (1 539 laps), and a service every 5 000 km that is late by steps of 500 km.
const WEAR_PARTS = ['tires' => 'street-tires-t2', 'suspension' => 'sport-suspension-t1', 'brakes' => 'disc-brakes-t2', 'exhaust' => 'sport-exhaust-t2'];

afterEach(fn () => Carbon::setTestNow());

/**
 * A car of a driver with parts that have this much wear (by slot), driving a run that is frozen from it like the game does, with fuel for `$laps` laps.
 * The seed is one that plans no breakdown for hundreds of laps, so that a test about the wear is not about a breakdown.
 *
 * @param  array<string, int>  $wear
 * @return array{0: User, 1: RaceRun, 2: Car}
 */
function wearRun(array $wear = ['tires' => 0], int $mileageM = 0, int $servicedAtM = 0, int $laps = 10, ?int $breakAfter = null, ?int $seed = null): array
{
    $driver = User::factory()->guest()->create();
    // The first run of a driver breaks down after a few laps for the tutorial, until the driver has skipped it.
    TutorialProgress::factory()->create(['user_id' => $driver->id, 'step_key' => 'wreck', 'state' => TutorialProgress::STATE_SKIPPED]);
    $car = Car::factory()->create(['user_id' => $driver->id, 'mileage_m' => $mileageM, 'serviced_at_m' => $servicedAtM, 'value_cents' => 114_000]);

    foreach ($wear as $slot => $ppb) {
        Part::factory()->state(['part_slug' => WEAR_PARTS[$slot], 'slot' => $slot])->mountedOn($car)->create(['wear_ppb' => $ppb]);
    }

    $inputs = app(RunInputsFactory::class)->makeForCar($car->refresh(), 'zlomowo-street');
    $freezer = new RunFreezer;

    for ($try = $seed ?? 0; ; $try++) {
        $runSeed = sprintf('%032x', $try);
        $frozen = $freezer->freeze($inputs, $runSeed, 0);

        if ($seed !== null || $frozen->breakAfterLaps === null || $frozen->breakAfterLaps > 600) {
            break;
        }
    }

    $run = RaceRun::factory()->create([
        'user_id' => $driver->id,
        'car_id' => $car->id,
        'cursor_ms' => RUN_T0,
        'fuel_ml' => $frozen->config['fuel_per_lap_ml'] * $laps,
        'break_after_laps' => $breakAfter,
        'recompute_after_laps' => $frozen->recomputeAfterLaps,
        'seed' => $runSeed,
        'config' => $frozen->config,
    ]);

    return [$driver, $run, $car];
}

function settleRun(RaceRun $run, int $hoursAfterT0): void
{
    app(RunService::class)->settle($run->id, RUN_T0 + $hoursAfterT0 * 3_600_000);
}

describe('driving wears the car', function () {
    it('puts the odometer and the wear of the parts forward by the laps, and tells when the service falls due', function () {
        [, $run, $car] = wearRun(['tires' => 0, 'brakes' => 0], mileageM: 4_990_000, laps: 10);
        $perLap = $run->config['wear']['ppb_per_lap'];

        settleRun($run, 2);
        $run->refresh();

        // 10 laps of 2.6 km, and the service fell due 10 km into them: the first pass ended four laps in, where the configuration was made again.
        expect($run->laps_done)->toBe(10)
            ->and($car->refresh()->mileage_m)->toBe(4_990_000 + 10 * 2_600)
            ->and(Part::query()->where('car_id', $car->id)->where('mounted_slot', 'tires')->value('wear_ppb'))->toBe(10 * $perLap['tires'])
            ->and(Part::query()->where('car_id', $car->id)->where('mounted_slot', 'brakes')->value('wear_ppb'))->toBe(10 * $perLap['brakes'])
            ->and(Settlement::query()->where('race_run_id', $run->id)->count())->toBe(2)
            ->and($run->recompute_after_laps)->toBeGreaterThan(10);

        $due = SettlementEvent::query()->where('type', 'service_due')->get();

        expect($due)->toHaveCount(1)
            ->and($due[0]->payload['mileage_km'])->toBe(5_000);
    });

    it('takes the performance of a car that is late for its service in the configuration made after the step, and not before it', function () {
        [, $run] = wearRun(['tires' => 0], mileageM: 5_400_000, laps: 80);
        $before = $run->config['lap_ms']['dry'];

        settleRun($run, 3);
        $run->refresh();

        // At 5 500 km the car is a whole step late: 4% off the performance for the laps after the 39th, which the new configuration has.
        expect($run->laps_done)->toBe(80)
            ->and($run->config['lap_ms']['dry'])->toBeGreaterThan($before);
    });

    it('is the same settled at t1 and then at t2 as at t2, over the stages of the wear and the steps of the service', function () {
        $setUp = fn (): array => wearRun(['tires' => 49_000_000, 'suspension' => 0], mileageM: 4_900_000, laps: 150, seed: 7);
        [$driverA, $a, $carA] = $setUp();
        [$driverB, $b, $carB] = $setUp();

        settleRun($a, 1);
        settleRun($a, 3);
        settleRun($b, 3);

        $state = fn (RaceRun $run, Car $car, User $driver): array => [
            'laps' => $run->refresh()->laps_done,
            'cursor' => $run->cursor_ms,
            'fuel' => $run->fuel_ml,
            'cash' => Wallet::query()->find($driver->id)->cash_cents,
            'xp' => Wallet::query()->find($driver->id)->xp,
            'mileage' => $car->refresh()->mileage_m,
            'tires' => Part::query()->where('car_id', $car->id)->where('mounted_slot', 'tires')->value('wear_ppb'),
            'suspension' => Part::query()->where('car_id', $car->id)->where('mounted_slot', 'suspension')->value('wear_ppb'),
            'lap_ms' => $run->config['lap_ms'],
            'break' => $run->break_after_laps,
            'recompute' => $run->recompute_after_laps,
            'status' => $run->status,
        ];

        // The tires were 1% short of a stage and the service was 100 km from falling due: both were passed on the way.
        expect($state($a, $carA, $driverA))->toBe($state($b, $carB, $driverB))
            ->and($a->refresh()->laps_done)->toBeGreaterThan(100);
    });

    it('freezes a car that stands for a breakdown again at the lap of its stage without moving the breakdown, and it does not drive', function () {
        [, $run] = wearRun(['tires' => 0], laps: 50);
        $run->forceFill(['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => 'breakdown', 'laps_done' => 5, 'break_after_laps' => 5, 'recompute_after_laps' => 5])->save();

        settleRun($run, 3);
        $run->refresh();

        expect($run->laps_done)->toBe(5)
            ->and($run->status)->toBe(RaceRun::STATUS_STOPPED)
            ->and($run->break_after_laps)->toBe(5)
            ->and($run->recompute_after_laps)->toBeGreaterThan(5);
    });

    it('says which part broke down: one that sits on the car, and the more worn it is the likelier', function () {
        [, $run, $car] = wearRun(['tires' => 0, 'brakes' => 900_000_000], laps: 10, breakAfter: 3);

        settleRun($run, 2);

        $breakdown = SettlementEvent::query()->where('type', 'breakdown')->first();

        expect($breakdown->payload['part'])->toBe((new CarWear)->breakdownPart($car->id, $run->seed, 3))
            ->and($breakdown->payload['part'])->toBeIn(['tires', 'brakes']);

        // Of two parts, one new and one nearly worn out, the worn one is nine times as likely as the new one plus the base: it is drawn most of the time.
        $parts = collect(range(1, 200))->map(fn (int $lap): ?string => (new CarWear)->breakdownPart($car->id, "seed-{$lap}", $lap))->countBy();

        expect($parts['brakes'])->toBeGreaterThan($parts['tires'] * 3);
    });

    it('names no part for a car that has none that wears', function () {
        [, $run] = wearRun([], laps: 10, breakAfter: 3);

        settleRun($run, 2);

        expect(SettlementEvent::query()->where('type', 'breakdown')->first()->payload)->not->toHaveKey('part');
    });

    it('wears the parts and the odometer by the kilometres of a journey', function () {
        [, $run, $car] = wearRun(['tires' => 0], laps: 1);
        $perKm = $run->config['wear']['ppb_per_km']['tires'];

        (new CarWear)->applyDistance($car->id, $run->config, 38);

        expect($car->refresh()->mileage_m)->toBe(38_000)
            ->and(Part::query()->where('car_id', $car->id)->value('wear_ppb'))->toBe(38 * $perKm)
            // And nothing for a run that was frozen before there was any wear.
            ->and((new CarWear)->applyDistance($car->id, ['lap_ms' => []], 38))->toBeNull();
    });
});

describe('the odometer of a car that is got', function () {
    it('starts at what the board of the wrecks says, and its first service falls due an interval after it', function () {
        $driver = User::factory()->guest()->create();

        $this->postJson('/api/v1/runs', ['car' => 'rdzak-gt-4', 'track' => 'zlomowo-street'], bearerFor($driver))->assertCreated();

        $car = Car::query()->where('user_id', $driver->id)->first();

        // The run is frozen with that odometer, so the first lap is already on it: the service falls due 5 000 km after the 260 000.
        $run = RaceRun::query()->where('user_id', $driver->id)->first();

        expect($car->mileage_m)->toBe(260_000_000)
            ->and($car->serviced_at_m)->toBe(260_000_000)
            ->and($run->config['wear']['length_m'])->toBe(2_600)
            ->and($run->recompute_after_laps)->toBe(1_924);
    });
});
