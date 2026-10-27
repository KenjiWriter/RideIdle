<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Domain\Run\Rarity;
use App\Models\Car;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

// Street tires of tier 2 cost $570. The step from +n to +n+1 costs 35% of that and 45% more for every step before it, and takes the mechanic of
// level 1 5 minutes and 40% more for every step before it, less 2%: 300 000 x 0.98 = 294 000 ms, 420 000 x 0.98 = 411 600 ms, 588 000 x 0.98 = 576 240 ms.
const UPGRADE_TIRES_PRICE = 57_000;
const UPGRADE_STEP_0_CENTS = 19_950;
const UPGRADE_STEP_0_MS = 294_000;

beforeEach(fn () => withoutTireFee());
afterEach(fn () => Carbon::setTestNow());

/** The driver has this much cash to spend. */
function upgradeCash(User $driver, int $cents): void
{
    (new Ledger)->post($driver->id, $cents, 'gift', "upgrade-test:{$driver->id}:{$cents}");
}

/** A driver at T0 with the car at level 3 and tires of tier 2 on it; the seed is fixed, so the car does not break down in the middle of a test. */
function upgradeDriver(array $part = []): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);
    $tires = Part::factory()->state(['part_slug' => 'street-tires-t2', 'slot' => 'tires', ...$part])->mountedOn($run->car)->create();

    return [$driver, $run, $tires];
}

function upgradeJob(User $driver, int $partId): TestResponse
{
    return test()->postJson('/api/v1/garage/jobs', ['kind' => 'upgrade', 'part' => $partId], bearerFor($driver));
}

function upgradeGarage(User $driver): TestResponse
{
    return test()->getJson('/api/v1/garage', bearerFor($driver));
}

describe('the rules of the upgrade', function () {
    it('multiplies the rarity by 3% for every level: a rare +7 is x1.48 of the model and a common +3 only x1.09', function () {
        $rules = app(Rules::class);

        expect($rules->statPpm(Rarity::Rare, 7))->toBe(1_476_200)
            ->and($rules->statPpm(Rarity::Common, 3))->toBe(1_090_000)
            ->and($rules->statPpm(Rarity::Epic))->toBe(1_380_000)
            ->and($rules->scaledStats(['grip' => 100, 'power_mult' => 1.02], Rarity::Legendary, 12))->toBe(['grip' => 217.6, 'power_mult' => 1.04352]);
    });

    it('limits an upgrade by the rarity and by the level of the car', function () {
        $rules = app(Rules::class);

        expect(array_map(fn (Rarity $rarity): int => $rules->upgradeMax($rarity), Rarity::cases()))->toBe([3, 5, 7, 9, 12])
            // The levels of the design: a car of level 3 gets +2, of level 6 +4, of level 9 +5 (a rare part is at most +7).
            ->and($rules->upgradeCap(Rarity::Legendary, 3))->toBe(2)
            ->and($rules->upgradeCap(Rarity::Legendary, 6))->toBe(4)
            ->and($rules->upgradeCap(Rarity::Legendary, 9))->toBe(5)
            ->and($rules->upgradeCap(Rarity::Common, 30))->toBe(3)
            ->and($rules->upgradeCap(Rarity::Legendary, 30))->toBe(12)
            ->and($rules->requiredCarLevelForUpgrade(3))->toBe(4);
    });

    it('prices the steps at 35% of the model and 45% more for every step, and times them at 5 minutes and 40% more for every step', function () {
        $rules = app(Rules::class);

        expect(array_map(fn (int $from): int => $rules->upgradeCostCents(UPGRADE_TIRES_PRICE, $from), [0, 1, 2]))->toBe([UPGRADE_STEP_0_CENTS, 28_928, 41_946])
            ->and(array_map(fn (int $from): int => $rules->upgradeJobMs($from), [0, 1, 2]))->toBe([UPGRADE_STEP_0_MS, 411_600, 576_240]);
    });
});

describe('upgrading a part', function () {
    it('is paid when it is queued, takes the car into the workshop, and gives the part its next level when the mechanic is done', function () {
        [$driver, $run, $tires] = upgradeDriver();
        upgradeCash($driver, 100_000);

        $queued = upgradeJob($driver, $tires->id)->assertCreated()
            ->assertJsonPath('data.workshop.jobs.0.kind', 'upgrade')
            ->assertJsonPath('data.workshop.jobs.0.status', 'working')
            ->assertJsonPath('data.workshop.jobs.0.cost_cents', UPGRADE_STEP_0_CENTS)
            ->assertJsonPath('data.workshop.jobs.0.upgrade_to', 1)
            ->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + UPGRADE_STEP_0_MS)
            ->assertJsonPath('data.car.run.stop_reason', 'workshop');

        expect(cashOf($run))->toBe(100_000 - UPGRADE_STEP_0_CENTS)
            ->and($tires->refresh()->upgrade_level)->toBe(0)
            ->and($queued->json('data.car.slots.0.part.upgrade'))->toBe(0);

        clockAt(UPGRADE_STEP_0_MS + 1_000);

        // The tires of tier 2 give a grip of 100: 3% more at +1.
        upgradeGarage($driver)->assertOk()
            ->assertJsonPath('data.workshop.jobs', [])
            ->assertJsonPath('data.car.run.stop_reason', null)
            ->assertJsonPath('data.car.slots.0.part.upgrade', 1)
            ->assertJsonPath('data.car.slots.0.part.stats.grip', 103);

        expect($tires->refresh()->upgrade_level)->toBe(1);
    });

    it('makes the car quicker once the part is upgraded, because the run is frozen again with it', function () {
        [$driver, $run, $tires] = upgradeDriver(['slot' => 'tires']);
        upgradeCash($driver, 100_000);
        $before = $run->refresh()->config['lap_ms']['dry'];

        upgradeJob($driver, $tires->id)->assertCreated();
        clockAt(UPGRADE_STEP_0_MS + 1_000);
        upgradeGarage($driver)->assertOk();

        // Street tires of tier 2 grip better at +1 than at +0, and a better grip is a shorter lap.
        expect($run->refresh()->config['lap_ms']['dry'])->toBeLessThan($before);
    });

    it('offers the next step with its price, its time and what stops it', function () {
        [$driver, , $tires] = upgradeDriver(['upgrade_level' => 2]);

        // A common part goes to +3, but a car of level 3 only to +2: the step to +3 waits for the level 4.
        upgradeGarage($driver)->assertOk()
            ->assertJsonPath('data.car.slots.0.part.upgrade_max', 3)
            ->assertJsonPath('data.car.slots.0.part.upgrade_next', [
                'to' => 3,
                'cost_cents' => 41_946,
                'duration_ms' => 576_240,
                'locked' => ['reason' => 'car_level', 'required' => 4, 'current' => 3],
            ]);

        $tires->forceFill(['upgrade_level' => 3])->save();

        upgradeGarage($driver)->assertOk()
            ->assertJsonPath('data.car.slots.0.part.upgrade_next', ['to' => null, 'cost_cents' => null, 'duration_ms' => null, 'locked' => ['reason' => 'max']]);
    });

    it('refuses a step that the level of the car does not allow, saying which level it takes, and one past the rarity', function () {
        [$driver, $run, $tires] = upgradeDriver(['upgrade_level' => 2]);
        upgradeCash($driver, 500_000);

        upgradeJob($driver, $tires->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'upgrade_car_level')
            ->assertJsonPath('message', 'Upgrading to +3 requires car level 4 (you have 3). You get XP by driving this car.');

        Car::query()->whereKey($run->car_id)->update(['level' => 30]);
        $tires->forceFill(['upgrade_level' => 3])->save();

        upgradeJob($driver, $tires->id)->assertStatus(422)->assertJsonPath('code', 'upgrade_max');

        // Nothing was charged and no job was made.
        expect(cashOf($run))->toBe(500_000)->and(WorkshopJob::query()->count())->toBe(0);
    });

    it('refuses a part that is not on the car, a part that is waiting in the workshop already, and a driver who cannot pay', function () {
        [$driver, $run, $tires] = upgradeDriver();
        $spare = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires']);

        upgradeJob($driver, $spare->id)->assertStatus(422)->assertJsonPath('code', 'part_not_mounted');

        // The price of the first step is $199.50.
        upgradeCash($driver, UPGRADE_STEP_0_CENTS - 1);
        upgradeJob($driver, $tires->id)->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');
        expect(WorkshopJob::query()->count())->toBe(0)->and(cashOf($run))->toBe(UPGRADE_STEP_0_CENTS - 1);

        upgradeCash($driver, 100_000);
        upgradeJob($driver, $tires->id)->assertCreated();
        upgradeJob($driver, $tires->id)->assertStatus(422)->assertJsonPath('code', 'part_busy');
    });

    it('gives the price back whole when the job is cancelled before the mechanic starts, and charges it again when it is queued again', function () {
        [$driver, $run, $tires] = upgradeDriver();
        $brakes = Part::factory()->state(['part_slug' => 'sport-exhaust-t2', 'slot' => 'exhaust'])->mountedOn($run->car)->create();
        upgradeCash($driver, 100_000);

        // The brakes are done first, so the tires wait.
        upgradeJob($driver, $brakes->id)->assertCreated();
        $second = upgradeJob($driver, $tires->id)->assertCreated()->assertJsonPath('data.workshop.jobs.1.status', 'queued');
        $jobId = $second->json('data.workshop.jobs.1.id');
        $spent = cashOf($run);

        test()->deleteJson("/api/v1/garage/jobs/{$jobId}", [], bearerFor($driver))->assertOk();
        expect(cashOf($run))->toBe($spent + UPGRADE_STEP_0_CENTS);

        // The same step again is charged again: the posting of the first one is not the key of this one.
        upgradeJob($driver, $tires->id)->assertCreated();
        expect(cashOf($run))->toBe($spent);
    });

    it('keeps the price of a job that the mechanic is at when it is cancelled: the work and the price are lost, and the part stays as it was', function () {
        [$driver, $run, $tires] = upgradeDriver();
        upgradeCash($driver, 100_000);

        $queued = upgradeJob($driver, $tires->id)->assertCreated()->assertJsonPath('data.workshop.jobs.0.status', 'working');
        test()->deleteJson('/api/v1/garage/jobs/'.$queued->json('data.workshop.jobs.0.id'), [], bearerFor($driver))->assertOk();

        expect(cashOf($run))->toBe(100_000 - UPGRADE_STEP_0_CENTS)->and($tires->refresh()->upgrade_level)->toBe(0);
    });

    it('keeps the level of the part when it comes off the car', function () {
        [$driver, , $tires] = upgradeDriver(['upgrade_level' => 2]);

        // The upgrade is a property of the part, like its rarity: taking it off does not take it away.
        test()->postJson('/api/v1/garage/jobs', ['kind' => 'unmount', 'part' => $tires->id], bearerFor($driver))->assertCreated();
        clockAt(300_000);
        upgradeGarage($driver)->assertOk()
            ->assertJsonPath('data.inventory.0.upgrade', 2)
            ->assertJsonPath('data.inventory.0.stats.grip', 106);
    });
});
