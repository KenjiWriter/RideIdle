<?php

declare(strict_types=1);

use App\Models\Car;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

// Street tires of tier 2 cost $570. Putting them right costs half of that for the whole condition they are short of, and takes the time of putting them on
// (120 000 x 2 x 0.98 = 235 200 ms) for the same: at 50% $142.50 and 117 600 ms. A service of a car worth $1 140 costs 4% of that ($45.60) and takes 15 minutes
// less the mechanic: 900 000 x 0.98 = 882 000 ms.
const REPAIR_HALF_CENTS = 14_250;
const REPAIR_HALF_MS = 117_600;
const SERVICE_CENTS = 4_560;
const SERVICE_MS = 882_000;

afterEach(fn () => Carbon::setTestNow());

/** A driver at T0 with a car that drives and cash, and tires on it with this much wear. @return array{0: User, 1: Car, 2: Part} */
function repairDriver(int $wearPpb = 500_000_000, int $cash = 100_000): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);
    $run->car->forceFill(['value_cents' => 114_000])->save();
    (new Ledger)->post($driver->id, $cash, 'gift', "repair-test:{$driver->id}");
    $tires = Part::factory()->state(['part_slug' => 'street-tires-t2', 'slot' => 'tires'])->mountedOn($run->car)->create(['wear_ppb' => $wearPpb]);

    return [$driver, $run->car, $tires];
}

function repairJob(User $driver, ?int $part): TestResponse
{
    return test()->postJson('/api/v1/garage/jobs', ['kind' => 'repair', 'part' => $part], bearerFor($driver));
}

function serviceJob(User $driver): TestResponse
{
    return test()->postJson('/api/v1/garage/jobs', ['kind' => 'service'], bearerFor($driver));
}

function repairGarage(User $driver): TestResponse
{
    return test()->getJson('/api/v1/garage', bearerFor($driver));
}

describe('the condition of a part', function () {
    it('shows in the garage: the percent that is left, what the statistics are with it, and what putting it right would cost and take', function () {
        [$driver] = repairDriver();

        // Half of its life is gone, so it gives 0.6 + 0.4 x 0.5 = 80% of its grip of 100.
        repairGarage($driver)->assertOk()
            ->assertJsonPath('data.car.slots.0.part.condition_percent', 50)
            ->assertJsonPath('data.car.slots.0.part.stats.grip', 80)
            ->assertJsonPath('data.car.slots.0.part.repair_next', ['cost_cents' => REPAIR_HALF_CENTS, 'duration_ms' => REPAIR_HALF_MS, 'locked' => null]);
    });

    it('says why a part cannot be put right: it is not worn, or it is worn out and has to be replaced', function () {
        [$driver, , $tires] = repairDriver(0);

        repairGarage($driver)->assertOk()->assertJsonPath('data.car.slots.0.part.repair_next', ['cost_cents' => null, 'duration_ms' => null, 'locked' => ['reason' => 'fine']]);

        $tires->forceFill(['wear_ppb' => 1_000_000_000])->save();

        repairGarage($driver)->assertOk()->assertJsonPath('data.car.slots.0.part.repair_next.locked.reason', 'worn_out');
    });
});

describe('repairing a part', function () {
    it('is paid when it is queued, stops the car when the part is on it, and takes all the wear away when the mechanic is done', function () {
        [$driver, $car, $tires] = repairDriver();

        repairJob($driver, $tires->id)->assertCreated()
            ->assertJsonPath('data.workshop.jobs.0.kind', 'repair')
            ->assertJsonPath('data.workshop.jobs.0.status', 'working')
            ->assertJsonPath('data.workshop.jobs.0.cost_cents', REPAIR_HALF_CENTS)
            ->assertJsonPath('data.workshop.jobs.0.part.id', $tires->id)
            ->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + REPAIR_HALF_MS)
            ->assertJsonPath('data.car.run.stop_reason', 'workshop');

        expect((new Ledger)->balance($driver->id))->toBe(100_000 - REPAIR_HALF_CENTS)
            ->and($tires->refresh()->wear_ppb)->toBe(500_000_000);

        clockAt(REPAIR_HALF_MS + 1_000);

        repairGarage($driver)->assertOk()
            ->assertJsonPath('data.car.slots.0.part.condition_percent', 100)
            ->assertJsonPath('data.car.slots.0.part.stats.grip', 100)
            ->assertJsonPath('data.car.run.stop_reason', null);

        expect($tires->refresh()->wear_ppb)->toBe(0);
    });

    it('does not stop the car when the part is in the inventory', function () {
        [$driver] = repairDriver();
        $spare = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires', 'wear_ppb' => 500_000_000]);

        repairJob($driver, $spare->id)->assertCreated()
            ->assertJsonPath('data.workshop.jobs.0.status', 'working')
            ->assertJsonPath('data.car.run.stop_reason', null);

        expect(WorkshopJob::query()->first()->car_id)->toBeNull();

        clockAt(REPAIR_HALF_MS + 1_000);
        repairGarage($driver)->assertOk()->assertJsonPath('data.inventory.0.condition_percent', 100);
    });

    it('refuses a part that is not worn, one that is worn out, one on another car, one that waits in a job, and a driver who cannot pay', function () {
        [$driver, $car, $tires] = repairDriver(0);

        repairJob($driver, $tires->id)->assertStatus(422)->assertJsonPath('code', 'part_not_worn');

        $tires->forceFill(['wear_ppb' => 1_000_000_000])->save();
        repairJob($driver, $tires->id)->assertStatus(422)->assertJsonPath('code', 'part_worn_out');

        $other = Car::factory()->create(['user_id' => $driver->id]);
        $elsewhere = Part::factory()->state(['part_slug' => 'sport-suspension-t1', 'slot' => 'suspension'])->mountedOn($other)->create(['wear_ppb' => 500_000_000]);
        repairJob($driver, $elsewhere->id)->assertStatus(422)->assertJsonPath('code', 'part_not_mounted');

        $tires->forceFill(['wear_ppb' => 500_000_000])->save();
        repairJob($driver, $tires->id)->assertCreated();
        repairJob($driver, $tires->id)->assertStatus(422)->assertJsonPath('code', 'part_busy');

        [$poor, , $poorTires] = repairDriver(500_000_000, REPAIR_HALF_CENTS - 1);
        repairJob($poor, $poorTires->id)->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');
        expect(WorkshopJob::query()->where('user_id', $poor->id)->count())->toBe(0);

        test()->postJson('/api/v1/garage/jobs', ['kind' => 'repair'], bearerFor($driver))->assertStatus(422)->assertJsonValidationErrors('part');
    });

    it('gives the price back when a repair that waits is cancelled, and loses it when the mechanic is at it', function () {
        [$driver, $car, $tires] = repairDriver();
        $brakes = Part::factory()->state(['part_slug' => 'disc-brakes-t2', 'slot' => 'brakes'])->mountedOn($car)->create(['wear_ppb' => 500_000_000]);

        // The brakes are done first, so the tires wait behind them.
        repairJob($driver, $brakes->id)->assertCreated();
        $waiting = repairJob($driver, $tires->id)->assertCreated()->assertJsonPath('data.workshop.jobs.1.status', 'queued');
        $spent = (new Ledger)->balance($driver->id);

        test()->deleteJson('/api/v1/garage/jobs/'.$waiting->json('data.workshop.jobs.1.id'), [], bearerFor($driver))->assertOk();
        expect((new Ledger)->balance($driver->id))->toBe($spent + REPAIR_HALF_CENTS);

        // The one at work: the price is gone with the work, and the wear stays.
        $atWork = repairGarage($driver)->json('data.workshop.jobs.0.id');
        test()->deleteJson("/api/v1/garage/jobs/{$atWork}", [], bearerFor($driver))->assertOk();

        expect((new Ledger)->balance($driver->id))->toBe($spent + REPAIR_HALF_CENTS)
            ->and($brakes->refresh()->wear_ppb)->toBe(500_000_000);
    });
});

describe('the service of the car', function () {
    it('tells in the garage how far the car has driven, how far its service is, what it costs and takes, and what being late takes off', function () {
        [$driver, $car] = repairDriver();
        $car->forceFill(['mileage_m' => 4_000_000])->save();

        repairGarage($driver)->assertOk()
            ->assertJsonPath('data.car.mileage_km', 4_000)
            ->assertJsonPath('data.car.service', ['every_km' => 5_000, 'since_km' => 4_000, 'due_in_km' => 1_000, 'overdue_km' => 0, 'penalty_percent' => 0, 'cost_cents' => SERVICE_CENTS, 'duration_ms' => SERVICE_MS]);

        // 1 200 km late is two whole steps of 500 km: 8% of the performance, which the statistics show.
        $car->forceFill(['mileage_m' => 6_200_000])->save();

        repairGarage($driver)->assertOk()
            ->assertJsonPath('data.car.service.due_in_km', -1_200)
            ->assertJsonPath('data.car.service.overdue_km', 1_200)
            ->assertJsonPath('data.car.service.penalty_percent', 8);
    });

    it('is paid when it is queued, stops the car, and starts the interval of the service again when the mechanic is done', function () {
        [$driver, $car] = repairDriver();
        $car->forceFill(['mileage_m' => 6_200_000])->save();

        serviceJob($driver)->assertCreated()
            ->assertJsonPath('data.workshop.jobs.0.kind', 'service')
            ->assertJsonPath('data.workshop.jobs.0.part', null)
            ->assertJsonPath('data.workshop.jobs.0.cost_cents', SERVICE_CENTS)
            ->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + SERVICE_MS)
            ->assertJsonPath('data.car.run.stop_reason', 'workshop');

        expect((new Ledger)->balance($driver->id))->toBe(100_000 - SERVICE_CENTS);

        clockAt(SERVICE_MS + 1_000);

        repairGarage($driver)->assertOk()
            ->assertJsonPath('data.car.run.stop_reason', null)
            ->assertJsonPath('data.car.service.since_km', 0)
            ->assertJsonPath('data.car.service.due_in_km', 5_000)
            ->assertJsonPath('data.car.service.penalty_percent', 0);

        expect($car->refresh()->serviced_at_m)->toBe($car->mileage_m);
    });

    it('is refused to a driver who cannot pay, and costs at least the least a service costs', function () {
        [$poor] = repairDriver(0, SERVICE_CENTS - 1);

        serviceJob($poor)->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');
        expect(WorkshopJob::query()->count())->toBe(0);

        // A car that is worth next to nothing is serviced for the least: $20.
        [$other, $cheap] = repairDriver(0);
        $cheap->forceFill(['value_cents' => 1_000])->save();
        repairGarage($other)->assertOk()->assertJsonPath('data.car.service.cost_cents', 2_000);
    });
});
