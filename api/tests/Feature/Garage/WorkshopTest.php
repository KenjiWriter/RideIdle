<?php

declare(strict_types=1);

use App\Models\Car;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

// Times of the jobs, from the rules: the base time of the slot at tier 1 times the tier, cut by 2% for the mechanic of level 1.
// Tires and exhaust of tier 2: 120 000 x 2 x 0.98. Tires of tier 1 taken off: 120 000 x 0.98 x 0.6.
const MOUNT_T2_MS = 235_200;
const UNMOUNT_TIRES_T1_MS = 70_560;

afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $body */
function queueJob(User $driver, string $kind, int $partId): TestResponse
{
    return test()->postJson('/api/v1/garage/jobs', ['kind' => $kind, 'part' => $partId], bearerFor($driver));
}

function getGarage(User $driver): TestResponse
{
    return test()->getJson('/api/v1/garage', bearerFor($driver));
}

function cancelJob(User $driver, int $jobId, array $headers = []): TestResponse
{
    return test()->deleteJson("/api/v1/garage/jobs/{$jobId}", [], [...bearerFor($driver), ...$headers]);
}

/** A part of the driver in the inventory. */
function inventoryPart(User $driver, string $slug = 'street-tires-t2', string $slot = 'tires'): Part
{
    return Part::factory()->create(['user_id' => $driver->id, 'part_slug' => $slug, 'slot' => $slot]);
}

/** A part of the car, mounted in its slot. */
function mountedPart(Car $car, string $slug, string $slot): Part
{
    return Part::factory()->state(['part_slug' => $slug, 'slot' => $slot])->mountedOn($car)->create();
}

function msAfterT0(): int
{
    return (int) Carbon::now()->getTimestampMs() - RUN_T0;
}

/**
 * Wallet, run and the car at level 3: a driver at T0 with a constant 75 s lap that pays $15.50.
 *
 * The seed is fixed: the jobs freeze the run again, and with a random seed the car breaks down on about one lap of
 * twelve, which would stop it in the middle of a test that counts its laps.
 */
function garageDriver(array $run = []): array
{
    [$driver, $race] = driverWithRun(['seed' => str_repeat('0', 32), ...$run]);

    return [$driver, $race, $race->car];
}

describe('queueing a job', function () {
    it('requires a token', function () {
        $this->getJson('/api/v1/garage')->assertUnauthorized();
        $this->postJson('/api/v1/garage/jobs', ['kind' => 'mount', 'part' => 1])->assertUnauthorized();
    });

    it('starts at once when the mechanic is free, and the car goes into the workshop', function () {
        [$driver, $run] = garageDriver();
        $part = inventoryPart($driver);

        queueJob($driver, 'mount', $part->id)
            ->assertCreated()
            ->assertJsonPath('data.workshop.jobs.0.kind', 'mount')
            ->assertJsonPath('data.workshop.jobs.0.status', 'working')
            ->assertJsonPath('data.workshop.jobs.0.start_ms', RUN_T0)
            ->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + MOUNT_T2_MS)
            ->assertJsonPath('data.workshop.jobs.0.part.slug', 'street-tires-t2')
            ->assertJsonPath('data.car.run.status', 'stopped')
            ->assertJsonPath('data.car.run.stop_reason', 'workshop');

        // The part is not on the car until the mechanic is done.
        expect($part->refresh()->car_id)->toBeNull()->and($run->refresh()->stop_reason)->toBe(RaceRun::STOP_WORKSHOP);
    });

    it('pays what the car earned before it went into the workshop', function () {
        [$driver, $run] = garageDriver();
        $part = inventoryPart($driver);

        clockAt(150_000);
        queueJob($driver, 'mount', $part->id)->assertCreated();

        // Two laps of 75 s, at $15.50 and 25 XP each.
        expect(cashOf($run))->toBe(3_100)
            ->and($run->refresh()->laps_done)->toBe(2)
            ->and($run->status)->toBe(RaceRun::STATUS_STOPPED);
    });

    it('does not pay for the time the car stands in the workshop', function () {
        [$driver, $run] = garageDriver();
        $part = inventoryPart($driver);
        queueJob($driver, 'mount', $part->id)->assertCreated();

        clockAt(200_000);
        getRun($driver)
            ->assertOk()
            ->assertJsonPath('data.status', 'stopped')
            ->assertJsonPath('data.stop_reason', 'workshop')
            ->assertJsonPath('data.laps_done', 0)
            ->assertJsonPath('summary.laps', 0)
            ->assertJsonPath('tow.available', false);

        expect(cashOf($run))->toBe(0);
    });

    it('puts the part on the car when the job is done, and the car drives again from that moment', function () {
        [$driver, $run, $car] = garageDriver();
        $part = inventoryPart($driver);
        // The stock Rdzak drives the base lap of the track, 75 s: the reference of its rules.
        $stock = $run->config['lap_ms']['dry'];
        queueJob($driver, 'mount', $part->id)->assertCreated();

        clockAt(MOUNT_T2_MS + 1_000);

        getGarage($driver)
            ->assertOk()
            ->assertJsonPath('data.workshop.jobs', [])
            ->assertJsonPath('data.car.slots.0.slot', 'tires')
            ->assertJsonPath('data.car.slots.0.part.slug', 'street-tires-t2')
            ->assertJsonPath('data.car.run.status', 'running')
            ->assertJsonPath('data.car.run.stop_reason', null);

        $run->refresh();

        // The new configuration is the one of the car with the tires on it: a faster lap than the stock car's.
        expect($car->mountedSlugs())->toBe(['tires' => 'street-tires-t2'])
            ->and($run->cursor_ms)->toBe(RUN_T0 + MOUNT_T2_MS)
            ->and($run->config['lap_ms']['dry'])->toBeLessThan($stock)
            ->and($run->laps_done)->toBe(0);
    });

    it('earns again after the job, laps counted from the end of it', function () {
        [$driver, $run] = garageDriver();
        queueJob($driver, 'mount', inventoryPart($driver)->id)->assertCreated();

        clockAt(MOUNT_T2_MS + 600_000);
        getRun($driver)->assertOk()->assertJsonPath('data.status', 'running');

        $run->refresh();

        expect($run->laps_done)->toBeGreaterThan(5)
            ->and(cashOf($run))->toBeGreaterThan(5 * 1_400)
            ->and($run->cursor_ms)->toBeGreaterThan(RUN_T0 + MOUNT_T2_MS);
    });

    it('does jobs one after the other, and the car stands until the last one is done', function () {
        [$driver, $run, $car] = garageDriver();
        $tires = inventoryPart($driver);
        $exhaust = inventoryPart($driver, 'sport-exhaust-t2', 'exhaust');

        queueJob($driver, 'mount', $tires->id)->assertCreated();
        $response = queueJob($driver, 'mount', $exhaust->id)->assertCreated();

        expect($response->json('data.workshop.jobs.0.status'))->toBe('working')
            ->and($response->json('data.workshop.jobs.1.status'))->toBe('queued')
            ->and($response->json('data.workshop.jobs.1.start_ms'))->toBe(RUN_T0 + MOUNT_T2_MS)
            ->and($response->json('data.workshop.jobs.1.finish_ms'))->toBe(RUN_T0 + 2 * MOUNT_T2_MS);

        clockAt(MOUNT_T2_MS + 60_000);
        getGarage($driver)
            ->assertJsonPath('data.car.slots.0.part.slug', 'street-tires-t2')
            ->assertJsonPath('data.car.slots.6.part', null)
            ->assertJsonPath('data.workshop.jobs.0.status', 'working')
            ->assertJsonPath('data.car.run.stop_reason', 'workshop');

        clockAt(2 * MOUNT_T2_MS + 1_000);
        getGarage($driver)
            ->assertJsonPath('data.car.slots.6.part.slug', 'sport-exhaust-t2')
            ->assertJsonPath('data.workshop.jobs', [])
            ->assertJsonPath('data.car.run.status', 'running');

        expect($run->refresh()->cursor_ms)->toBe(RUN_T0 + 2 * MOUNT_T2_MS)
            ->and($car->mountedSlugs())->toBe(['exhaust' => 'sport-exhaust-t2', 'tires' => 'street-tires-t2']);
    });

    it('carries out every job that is due when the driver comes back after a long time', function () {
        [$driver, $run, $car] = garageDriver();
        queueJob($driver, 'mount', inventoryPart($driver)->id)->assertCreated();
        queueJob($driver, 'mount', inventoryPart($driver, 'sport-exhaust-t2', 'exhaust')->id)->assertCreated();

        // Two hours later: both jobs are long done, and the car has been driving since the second one.
        clockAt(7_200_000);
        getRun($driver)->assertOk();

        $run->refresh();

        expect($car->mountedSlugs())->toHaveCount(2)
            ->and($run->status)->toBe(RaceRun::STATUS_STOPPED)
            ->and($run->stop_reason)->toBe('fuel_empty')
            ->and($run->laps_done)->toBeGreaterThan(20)
            ->and(WorkshopJob::query()->where('status', '!=', 'done')->count())->toBe(0);
    });

    it('lets the car drive between two jobs that are queued with a gap', function () {
        [$driver, $run] = garageDriver();
        queueJob($driver, 'mount', inventoryPart($driver)->id)->assertCreated();

        clockAt(MOUNT_T2_MS + 400_000);
        queueJob($driver, 'mount', inventoryPart($driver, 'sport-exhaust-t2', 'exhaust')->id)
            ->assertCreated()
            ->assertJsonPath('data.workshop.jobs.0.start_ms', RUN_T0 + MOUNT_T2_MS + 400_000);

        // What it earned between the jobs is paid when it goes back in.
        expect(cashOf($run))->toBeGreaterThan(0)->and($run->refresh()->stop_reason)->toBe(RaceRun::STOP_WORKSHOP);
    });
});

describe('taking a part off and replacing one', function () {
    it('takes a part off the car and back to the inventory', function () {
        [$driver, , $car] = garageDriver();
        $part = mountedPart($car, 'sport-exhaust-t2', 'exhaust');

        queueJob($driver, 'unmount', $part->id)
            ->assertCreated()
            ->assertJsonPath('data.workshop.jobs.0.kind', 'unmount')
            // 60% of the time of putting it on: 120 000 x 2 x 0.98 x 0.6.
            ->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + 141_120);

        clockAt(150_000);
        getGarage($driver)
            ->assertJsonPath('data.car.slots.6.part', null)
            ->assertJsonPath('data.inventory.0.slug', 'sport-exhaust-t2')
            ->assertJsonPath('data.inventory.0.mounted', false)
            ->assertJsonPath('data.car.stats.accel', 97);

        expect($part->refresh()->car_id)->toBeNull()->and($part->mounted_slot)->toBeNull();
    });

    it('takes the old part off first when a slot has one, as a job of its own', function () {
        [$driver, , $car] = garageDriver();
        $old = mountedPart($car, 'street-tires-t1', 'tires');
        $new = inventoryPart($driver);

        $response = queueJob($driver, 'mount', $new->id)->assertCreated();

        expect($response->json('data.workshop.jobs'))->toHaveCount(2)
            ->and($response->json('data.workshop.jobs.0'))->toMatchArray(['kind' => 'unmount', 'start_ms' => RUN_T0, 'finish_ms' => RUN_T0 + UNMOUNT_TIRES_T1_MS])
            ->and($response->json('data.workshop.jobs.0.part.id'))->toBe($old->id)
            ->and($response->json('data.workshop.jobs.1'))->toMatchArray(['kind' => 'mount', 'start_ms' => RUN_T0 + UNMOUNT_TIRES_T1_MS, 'finish_ms' => RUN_T0 + UNMOUNT_TIRES_T1_MS + MOUNT_T2_MS]);

        clockAt(UNMOUNT_TIRES_T1_MS + MOUNT_T2_MS + 1_000);
        getGarage($driver)->assertOk()->assertJsonPath('data.car.slots.0.part.slug', 'street-tires-t2')->assertJsonPath('data.inventory.0.slug', 'street-tires-t1');
    });

    it('shows the statistics of the car with its parts and without them', function () {
        [$driver, , $car] = garageDriver();
        mountedPart($car, 'sport-exhaust-t2', 'exhaust');
        mountedPart($car, 'disc-brakes-t2', 'brakes');

        getGarage($driver)
            ->assertOk()
            ->assertJsonPath('data.car.stats.accel', 98.94)
            ->assertJsonPath('data.car.stats.braking', 97.5)
            ->assertJsonPath('data.car.base_stats.accel', 97)
            ->assertJsonPath('data.car.base_stats.braking', 90);
    });
});

describe('what the workshop refuses', function () {
    it('refuses a part that needs a higher level than the car has', function () {
        [$driver, , $car] = garageDriver();
        $car->update(['level' => 2]);
        $part = inventoryPart($driver);

        queueJob($driver, 'mount', $part->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'car_level_required')
            ->assertJsonPath('details', ['required' => 3, 'current' => 2])
            ->assertJsonPath('message', 'Requires car level 3 (you have 2). You get XP by driving this car.');

        expect(WorkshopJob::query()->count())->toBe(0);
    });

    it('refuses a part of another driver, and one that does not exist', function () {
        [$driver] = garageDriver();
        $other = User::factory()->guest()->create();
        $theirs = inventoryPart($other);

        queueJob($driver, 'mount', $theirs->id)->assertStatus(422)->assertJsonPath('code', 'part_not_found');
        queueJob($driver, 'mount', 999_999)->assertStatus(422)->assertJsonPath('code', 'part_not_found');
    });

    it('refuses a part that is on the car for mounting and one that is not for taking off', function () {
        [$driver, , $car] = garageDriver();
        $onTheCar = mountedPart($car, 'sport-exhaust-t2', 'exhaust');
        $spare = inventoryPart($driver);

        queueJob($driver, 'mount', $onTheCar->id)->assertStatus(422)->assertJsonPath('code', 'part_mounted');
        queueJob($driver, 'unmount', $spare->id)->assertStatus(422)->assertJsonPath('code', 'part_not_mounted');
    });

    it('refuses a part that is in the queue already, and a second part for a slot that is taken in the queue', function () {
        [$driver] = garageDriver();
        $first = inventoryPart($driver);
        $second = inventoryPart($driver, 'street-tires-t1');

        queueJob($driver, 'mount', $first->id)->assertCreated();

        queueJob($driver, 'mount', $first->id)->assertStatus(422)->assertJsonPath('code', 'part_busy');
        queueJob($driver, 'mount', $second->id)->assertStatus(422)->assertJsonPath('code', 'slot_busy');

        expect(WorkshopJob::query()->count())->toBe(1);
    });

    it('refuses a job when the queue is full, and the replacement that would not fit', function () {
        [$driver] = garageDriver();

        foreach ([['sport-exhaust-t2', 'exhaust'], ['street-tires-t2', 'tires'], ['sport-suspension-t1', 'suspension'], ['disc-brakes-t2', 'brakes']] as [$slug, $slot]) {
            queueJob($driver, 'mount', inventoryPart($driver, $slug, $slot)->id)->assertCreated();
        }

        queueJob($driver, 'mount', inventoryPart($driver, 'factory-cooling-t1', 'cooling')->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'queue_full')
            ->assertJsonPath('message', 'The workshop queue is full.');

        expect(WorkshopJob::query()->count())->toBe(4);
    });

    it('does not take a place of the queue for a replacement that would not fit', function () {
        [$driver, , $car] = garageDriver();
        foreach ([['factory-cooling-t1', 'cooling'], ['sport-suspension-t1', 'suspension'], ['disc-brakes-t2', 'brakes']] as [$slug, $slot]) {
            queueJob($driver, 'mount', inventoryPart($driver, $slug, $slot)->id)->assertCreated();
        }
        $old = mountedPart($car, 'street-tires-t1', 'tires');

        // Three places are taken. A replacement needs two, and only one is left.
        queueJob($driver, 'mount', inventoryPart($driver)->id)->assertStatus(422)->assertJsonPath('code', 'queue_full');

        expect(WorkshopJob::query()->count())->toBe(3)->and($old->refresh()->isMounted())->toBeTrue();
    });

    it('refuses to work for a driver who has no car', function () {
        $driver = User::factory()->guest()->create();
        $part = inventoryPart($driver);

        queueJob($driver, 'mount', $part->id)->assertStatus(422)->assertJsonPath('code', 'no_car');
    });

    it('validates the body', function () {
        [$driver] = garageDriver();

        test()->postJson('/api/v1/garage/jobs', ['kind' => 'paint', 'part' => 1], bearerFor($driver))->assertStatus(422)->assertJsonValidationErrors('kind');
        test()->postJson('/api/v1/garage/jobs', ['kind' => 'mount'], bearerFor($driver))->assertStatus(422)->assertJsonValidationErrors('part');
    });

    it('answers in the language of the driver', function () {
        [$driver] = garageDriver();

        test()->postJson('/api/v1/garage/jobs', ['kind' => 'mount', 'part' => 999_999], [...bearerFor($driver), 'X-Locale' => 'de'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Du besitzt dieses Teil nicht.');
    });
});

describe('cancelling a job', function () {
    it('requires a token', function () {
        $this->deleteJson('/api/v1/garage/jobs/1')->assertUnauthorized();
    });

    it('cancels a job that waits, and leaves the part where it was', function () {
        [$driver, $run] = garageDriver();
        $tires = inventoryPart($driver);
        $exhaust = inventoryPart($driver, 'sport-exhaust-t2', 'exhaust');
        $queued = queueJob($driver, 'mount', $tires->id)->assertCreated();
        $second = queueJob($driver, 'mount', $exhaust->id)->assertCreated()->json('data.workshop.jobs.1.id');

        cancelJob($driver, $second)
            ->assertOk()
            ->assertJsonPath('cancelled', [$second])
            ->assertJsonCount(1, 'data.workshop.jobs')
            ->assertJsonPath('data.workshop.jobs.0.id', $queued->json('data.workshop.jobs.0.id'))
            ->assertJsonPath('data.workshop.jobs.0.status', 'working')
            ->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + MOUNT_T2_MS)
            // The part is in the inventory, and the car is still in the workshop for the job that was not cancelled.
            ->assertJsonPath('data.inventory.1.slug', 'sport-exhaust-t2')
            ->assertJsonPath('data.car.run.stop_reason', 'workshop');

        expect(WorkshopJob::query()->count())->toBe(1)->and($exhaust->refresh()->car_id)->toBeNull()->and(cashOf($run))->toBe(0);
    });

    it('moves the jobs behind it up, each lasting what it did', function () {
        [$driver] = garageDriver();
        $jobs = [];

        foreach ([['street-tires-t2', 'tires'], ['sport-exhaust-t2', 'exhaust'], ['sport-suspension-t1', 'suspension']] as [$slug, $slot]) {
            $jobs = queueJob($driver, 'mount', inventoryPart($driver, $slug, $slot)->id)->assertCreated()->json('data.workshop.jobs');
        }

        $last = $jobs[2];
        $duration = $last['finish_ms'] - $last['start_ms'];

        cancelJob($driver, $jobs[1]['id'])->assertOk()->assertJsonCount(2, 'data.workshop.jobs')
            ->assertJsonPath('data.workshop.jobs.1.id', $last['id'])
            ->assertJsonPath('data.workshop.jobs.1.status', 'queued')
            ->assertJsonPath('data.workshop.jobs.1.start_ms', RUN_T0 + MOUNT_T2_MS)
            ->assertJsonPath('data.workshop.jobs.1.finish_ms', RUN_T0 + MOUNT_T2_MS + $duration);
    });

    it('cancels the job the mechanic is at: the work is lost and the next job starts at once', function () {
        [$driver, $run] = garageDriver();
        $tires = inventoryPart($driver);
        $exhaust = inventoryPart($driver, 'sport-exhaust-t2', 'exhaust');
        $first = queueJob($driver, 'mount', $tires->id)->assertCreated()->json('data.workshop.jobs.0.id');
        $duration = queueJob($driver, 'mount', $exhaust->id)->assertCreated()->json('data.workshop.jobs.1.finish_ms') - (RUN_T0 + MOUNT_T2_MS);

        clockAt(100_000);
        cancelJob($driver, $first)
            ->assertOk()
            ->assertJsonPath('cancelled', [$first])
            ->assertJsonCount(1, 'data.workshop.jobs')
            ->assertJsonPath('data.workshop.jobs.0.part.slug', 'sport-exhaust-t2')
            ->assertJsonPath('data.workshop.jobs.0.status', 'working')
            ->assertJsonPath('data.workshop.jobs.0.start_ms', RUN_T0 + 100_000)
            ->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + 100_000 + $duration)
            // The car did not leave the workshop between the two jobs.
            ->assertJsonPath('data.car.run.status', 'stopped')
            ->assertJsonPath('data.car.run.stop_reason', 'workshop');

        expect($tires->refresh()->car_id)->toBeNull()->and($run->refresh()->stop_reason)->toBe(RaceRun::STOP_WORKSHOP);
    });

    it('sends the car back on the track from now when it was the only job', function () {
        [$driver, $run] = garageDriver();
        $tires = inventoryPart($driver);
        $job = queueJob($driver, 'mount', $tires->id)->assertCreated()->json('data.workshop.jobs.0.id');

        clockAt(100_000);
        cancelJob($driver, $job)
            ->assertOk()
            ->assertJsonPath('data.workshop.jobs', [])
            ->assertJsonPath('data.car.run.status', 'running')
            ->assertJsonPath('data.car.run.stop_reason', null)
            ->assertJsonPath('data.car.slots.0.part', null);

        // It earned nothing while it stood, and its first lap starts at the moment the job was cancelled.
        $run->refresh();
        expect($run->cursor_ms)->toBe(RUN_T0 + 100_000)->and($run->laps_done)->toBe(0)->and(cashOf($run))->toBe(0);

        clockAt(100_000 + 2 * $run->config['lap_ms']['dry']);
        getRun($driver)->assertOk()->assertJsonPath('data.status', 'running');

        expect($run->refresh()->laps_done)->toBeGreaterThan(0)->and($tires->refresh()->car_id)->toBeNull();
    });

    it('does not start the car when the job that was cancelled had not stopped it', function () {
        [$driver, $run] = garageDriver(['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => 'breakdown']);
        $job = queueJob($driver, 'mount', inventoryPart($driver)->id)->assertCreated()->json('data.workshop.jobs.0.id');

        cancelJob($driver, $job)->assertOk();

        expect($run->refresh()->status)->toBe(RaceRun::STATUS_STOPPED)->and($run->stop_reason)->toBe('breakdown');
    });

    it('cancels the putting on of the part that was to replace the one whose taking off is cancelled', function () {
        [$driver, , $car] = garageDriver();
        $old = mountedPart($car, 'street-tires-t1', 'tires');
        $new = inventoryPart($driver);
        [$unmount, $mount] = queueJob($driver, 'mount', $new->id)->assertCreated()->json('data.workshop.jobs');

        cancelJob($driver, $unmount['id'])
            ->assertOk()
            ->assertJsonPath('cancelled', [$unmount['id'], $mount['id']])
            ->assertJsonPath('data.workshop.jobs', [])
            ->assertJsonPath('data.car.slots.0.part.slug', 'street-tires-t1')
            ->assertJsonPath('data.inventory.0.slug', 'street-tires-t2')
            ->assertJsonPath('data.car.run.status', 'running');

        expect($old->refresh()->isMounted())->toBeTrue()->and(WorkshopJob::query()->count())->toBe(0);
    });

    it('keeps the taking off of the old part when only the putting on of the new one is cancelled', function () {
        [$driver, , $car] = garageDriver();
        $old = mountedPart($car, 'street-tires-t1', 'tires');
        [$unmount, $mount] = queueJob($driver, 'mount', inventoryPart($driver)->id)->assertCreated()->json('data.workshop.jobs');

        cancelJob($driver, $mount['id'])
            ->assertOk()
            ->assertJsonPath('cancelled', [$mount['id']])
            ->assertJsonCount(1, 'data.workshop.jobs')
            ->assertJsonPath('data.workshop.jobs.0.id', $unmount['id'])
            ->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + UNMOUNT_TIRES_T1_MS);

        clockAt(UNMOUNT_TIRES_T1_MS + 1_000);
        getGarage($driver)->assertOk()->assertJsonPath('data.car.slots.0.part', null)->assertJsonPath('data.car.run.status', 'running');

        expect($old->refresh()->isMounted())->toBeFalse();
    });

    it('lets the part be queued again, and gives the place in the queue back', function () {
        [$driver] = garageDriver();
        $jobs = [];

        foreach ([['sport-exhaust-t2', 'exhaust'], ['street-tires-t2', 'tires'], ['sport-suspension-t1', 'suspension'], ['disc-brakes-t2', 'brakes']] as [$slug, $slot]) {
            $part = inventoryPart($driver, $slug, $slot);
            $jobs[$slot] = [$part, queueJob($driver, 'mount', $part->id)->assertCreated()->json('data.workshop.jobs.'.count($jobs).'.id')];
        }

        [$tires, $tiresJob] = $jobs['tires'];
        cancelJob($driver, $tiresJob)->assertOk()->assertJsonCount(3, 'data.workshop.jobs');

        queueJob($driver, 'mount', $tires->id)->assertCreated()->assertJsonCount(4, 'data.workshop.jobs');
    });

    it('refuses a job that is done, and does not undo it', function () {
        [$driver, , $car] = garageDriver();
        $job = queueJob($driver, 'mount', inventoryPart($driver)->id)->assertCreated()->json('data.workshop.jobs.0.id');

        clockAt(MOUNT_T2_MS + 1_000);
        cancelJob($driver, $job)
            ->assertStatus(422)
            ->assertJsonPath('code', 'job_done')
            ->assertJsonPath('message', 'That job is already done.');

        getGarage($driver)->assertOk()->assertJsonPath('data.car.slots.0.part.slug', 'street-tires-t2');

        expect($car->mountedSlugs())->toHaveCount(1);
    });

    it('refuses a job that is not there, a job of another driver, and a job that was cancelled already', function () {
        [$driver] = garageDriver();
        [$other] = driverWithRun();
        $mine = queueJob($driver, 'mount', inventoryPart($driver)->id)->assertCreated()->json('data.workshop.jobs.0.id');
        $theirs = queueJob($other, 'mount', inventoryPart($other)->id)->assertCreated()->json('data.workshop.jobs.0.id');

        cancelJob($driver, 999_999)->assertStatus(422)->assertJsonPath('code', 'job_not_found');
        cancelJob($driver, $theirs)->assertStatus(422)->assertJsonPath('code', 'job_not_found');
        cancelJob($driver, $mine)->assertOk();
        cancelJob($driver, $mine)->assertStatus(422)->assertJsonPath('code', 'job_not_found');

        // The job of the other driver is where it was.
        expect(WorkshopJob::query()->where('user_id', $other->id)->count())->toBe(1);
    });

    it('answers in the language of the driver', function () {
        [$driver] = garageDriver();

        cancelJob($driver, 999_999, ['X-Locale' => 'pl'])->assertStatus(422)->assertJsonPath('message', 'Nie ma takiego zlecenia.');
    });
});

describe('a car that stands for another reason', function () {
    it('keeps standing for a breakdown after the job, with the new parts in its configuration', function () {
        [$driver, $run, $car] = garageDriver(['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => 'breakdown']);
        $stock = $run->config['lap_ms']['dry'];

        queueJob($driver, 'mount', inventoryPart($driver)->id)->assertCreated();
        clockAt(MOUNT_T2_MS + 1_000);
        getGarage($driver)->assertOk();

        $run->refresh();

        expect($run->status)->toBe(RaceRun::STATUS_STOPPED)
            ->and($run->stop_reason)->toBe('breakdown')
            ->and($run->config['lap_ms']['dry'])->not->toBe($stock)
            ->and($car->mountedSlugs())->toHaveCount(1);
    });

    it('does not start driving when it is refueled while it stands in the workshop', function () {
        [$driver, $run] = garageDriver(['fuel_ml' => 10_000]);
        (new Ledger)->post($driver->id, 100_000, 'gift', 'seed-money:'.$driver->id);

        queueJob($driver, 'mount', inventoryPart($driver)->id)->assertCreated();
        clockAt(30_000);

        test()->postJson("/api/v1/runs/{$run->id}/refuel", ['request_id' => 'r1'], bearerFor($driver))
            ->assertOk()
            ->assertJsonPath('data.status', 'stopped')
            ->assertJsonPath('data.stop_reason', 'workshop')
            ->assertJsonPath('data.fuel_ml', 45_000);
    });
});

describe('the garage', function () {
    it('shows the car, its nine slots, the inventory and the workshop', function () {
        [$driver, , $car] = garageDriver();
        mountedPart($car, 'sport-exhaust-t2', 'exhaust');
        inventoryPart($driver);

        $response = getGarage($driver)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('now_ms', RUN_T0)
            ->assertJsonPath('data.car.level', 3)
            ->assertJsonPath('data.car.vehicle.slug', 'rdzak-gt-4')
            ->assertJsonPath('data.car.vehicle.engine', 'R4 1.8')
            ->assertJsonPath('data.car.vehicle.drivetrain', 'fwd')
            ->assertJsonPath('data.car.ceilings', ['accel' => 400, 'top_speed' => 450, 'grip' => 250, 'handling' => 250, 'braking' => 250, 'clearance' => 200])
            ->assertJsonPath('data.car.tank_ml', 45_000)
            ->assertJsonPath('data.car.run.status', 'running')
            ->assertJsonPath('data.workshop.queue_max', 4)
            ->assertJsonPath('data.workshop.mechanic', ['level' => 1, 'time_cut_ppm' => 20_000])
            ->assertJsonPath('data.inventory.0.slug', 'street-tires-t2');

        expect(array_column($response->json('data.car.slots'), 'slot'))->toBe(['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'exhaust', 'cooling', 'tank'])
            ->and($response->json('data.car.slots.3.built_in'))->toBeTrue()
            ->and($response->json('data.car.slots.6.part.slug'))->toBe('sport-exhaust-t2');
    });

    it('is empty for a driver who has no car', function () {
        $driver = User::factory()->guest()->create();

        getGarage($driver)->assertOk()->assertJsonPath('data.car', null)->assertJsonPath('data.inventory', [])->assertJsonPath('data.workshop.jobs', []);
    });

    it('does not show the parts and jobs of another driver', function () {
        [$driver] = garageDriver();
        [$other, , $otherCar] = garageDriver();
        inventoryPart($other);
        mountedPart($otherCar, 'sport-exhaust-t2', 'exhaust');

        getGarage($driver)->assertOk()->assertJsonPath('data.inventory', [])->assertJsonPath('data.car.slots.6.part', null);
    });
});
