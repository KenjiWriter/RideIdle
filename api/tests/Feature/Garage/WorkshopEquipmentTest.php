<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Domain\Workshop\WorkshopGear;
use App\Models\CityWorkshop;
use App\Models\LedgerEntry;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Services\Ledger;
use App\Services\WorkshopEquipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

// Tires and exhaust of tier 2: 120 000 x 2 x 0.98. The suspension of tier 1: 170 000 x 0.98. A merge of two tires of tier 2 takes twice a mounting.
const GEAR_MOUNT_T2_MS = 235_200;
const GEAR_MOUNT_SUSPENSION_T1_MS = 166_600;
const GEAR_MERGE_T2_MS = 470_400;
const GEAR_TIRE_FEE_CENTS = 3_000;

afterEach(fn () => Carbon::setTestNow());

/** A driver at T0 with a car that drives (the seed is fixed) and cash to buy equipment with. */
function gearDriver(int $cash = 5_000_000): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);
    (new Ledger)->post($driver->id, $cash, 'gift', "gear-test:{$driver->id}");

    return [$driver, $run];
}

function buyGear(User $driver, string $item, ?string $requestId = null): TestResponse
{
    return test()->postJson('/api/v1/garage/equipment', ['item' => $item, 'request_id' => $requestId ?? (string) Str::uuid()], bearerFor($driver));
}

/** Buys the item `$times` times, one level after the other. */
function buyGearTimes(User $driver, string $item, int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        buyGear($driver, $item)->assertCreated();
    }
}

function gearGarage(User $driver): TestResponse
{
    return test()->getJson('/api/v1/garage', bearerFor($driver));
}

function gearJob(User $driver, string $kind, ?int $part = null, ?int $with = null): TestResponse
{
    return test()->postJson('/api/v1/garage/jobs', ['kind' => $kind, 'part' => $part, ...($with === null ? [] : ['with' => $with])], bearerFor($driver));
}

function gearStock(User $driver, string $slug = 'street-tires-t2', string $slot = 'tires'): Part
{
    return Part::factory()->create(['user_id' => $driver->id, 'part_slug' => $slug, 'slot' => $slot]);
}

/** @return array{0: int, 1: int} the ids of two parts that can be merged */
function gearPair(User $driver): array
{
    return [gearStock($driver)->id, gearStock($driver)->id];
}

/** The length of the job in the answer, and the answer's job by its place in the queue. */
function gearLength(TestResponse $response, int $index): int
{
    $job = $response->json("data.workshop.jobs.{$index}");

    return $job['finish_ms'] - $job['start_ms'];
}

describe('the rules of the equipment', function () {
    it('multiplies the time of a job by the item of its slot and by the toolkit, and by nothing for the workshop that bought nothing', function () {
        $rules = app(Rules::class);
        $start = WorkshopGear::start();
        $manual = new WorkshopGear(tireMachine: 1);
        $full = new WorkshopGear(tireMachine: 2, lift: 3, hoist: 2, toolkit: 2, bays: 4, mechanics: 4);

        // Tires of tier 2 are put on in 235 s, in 118 s with a manual machine and in 66 s with the automatic one and the electric toolkit.
        expect($rules->workshopJobMs('tires', 2))->toBe(GEAR_MOUNT_T2_MS)
            ->and($rules->workshopJobMs('tires', 2, gear: $start))->toBe(GEAR_MOUNT_T2_MS)
            ->and($rules->workshopJobMs('tires', 2, gear: $manual))->toBe(117_600)
            ->and($rules->workshopJobMs('tires', 2, gear: new WorkshopGear(tireMachine: 2)))->toBe(82_320)
            ->and($rules->workshopJobMs('tires', 2, gear: $full))->toBe(65_856)
            // The four-post lift takes the suspension of tier 1 from 167 s to 60 s and with the electric toolkit to 48 s.
            ->and($rules->workshopJobMs('suspension', 1, gear: new WorkshopGear(lift: 3)))->toBe(59_976)
            ->and($rules->workshopJobMs('suspension', 1, gear: $full))->toBe(47_981)
            // The hoist works on the engine, the gearbox and the boost.
            ->and($rules->workshopJobMs('engine', 1, gear: new WorkshopGear(hoist: 2)))->toBe(158_760)
            ->and($rules->workshopJobMs('boost', 1, gear: new WorkshopGear(hoist: 1)))->toBe(155_232)
            // Taking a part off keeps its share of the time.
            ->and($rules->workshopJobMs('tires', 2, removal: true, gear: $manual))->toBe(70_560);
    });

    it('lets the toolkit only speed up what has no slot of its own: a merge, a service, and the parts of a slot that has no item', function () {
        $rules = app(Rules::class);
        $electric = new WorkshopGear(toolkit: 2);
        $lifted = new WorkshopGear(tireMachine: 2, lift: 3, hoist: 2);

        expect($rules->mergeJobMs('tires', 2))->toBe(GEAR_MERGE_T2_MS)
            // A merge is two mountings (an electric toolkit takes 20% off each), and the tire machine has nothing to do with parts that are not on a car.
            ->and($rules->mergeJobMs('tires', 2, $electric))->toBe(376_320)
            ->and($rules->mergeJobMs('tires', 2, new WorkshopGear(tireMachine: 2)))->toBe(GEAR_MERGE_T2_MS)
            ->and($rules->serviceJobMs())->toBe(882_000)
            ->and($rules->serviceJobMs($electric))->toBe(705_600)
            ->and($rules->serviceJobMs($lifted))->toBe(882_000)
            ->and($rules->workshopJobMs('cooling', 1, gear: $lifted))->toBe(117_600)
            ->and($rules->workshopJobMs('cooling', 1, gear: $electric))->toBe(94_080)
            ->and($rules->equipmentItemOf('cooling'))->toBeNull()
            ->and($rules->equipmentItemOf('tires'))->toBe('tire_machine')
            ->and($rules->equipmentItemOf('exhaust'))->toBe('lift')
            ->and($rules->equipmentItemOf('gearbox'))->toBe('hoist');
    });

    it('speeds up an upgrade by the equipment of the slot, and the longer ones by as much', function () {
        $rules = app(Rules::class);

        // The first step of the upgrade of tires: 294 s; the third one 576 s, and 13 minutes for the fourth.
        expect($rules->upgradeJobMs(0))->toBe(294_000)
            ->and($rules->upgradeJobMs(0, new WorkshopGear(tireMachine: 2, toolkit: 2), 'tires'))->toBe(82_320)
            ->and($rules->upgradeJobMs(0, new WorkshopGear(tireMachine: 2, toolkit: 2), 'exhaust'))->toBe(235_200);
    });

    it('prices the equipment at $14 450 for all of it, the bays at $23 000 and the mechanics at $18 800, bought one after the other', function () {
        $rules = app(Rules::class);
        $total = function (string $item) use ($rules): int {
            $sum = 0;
            $level = $item === 'bays' || $item === 'mechanics' ? 1 : 0;

            while (($price = $rules->gearNextPriceCents($item, $level)) !== null) {
                $sum += $price;
                $level++;
            }

            return $sum;
        };

        expect(array_sum(array_map($total, WorkshopGear::ITEMS)))->toBe(1_445_000)
            ->and($total('bays'))->toBe(2_300_000)
            ->and($total('mechanics'))->toBe(1_880_000)
            ->and($rules->gearNextPriceCents('tire_machine', 0))->toBe(40_000)
            ->and($rules->gearNextPriceCents('lift', 2))->toBe(400_000)
            ->and($rules->gearNextPriceCents('toolkit', 2))->toBeNull()
            ->and([$rules->gearMax('tire_machine'), $rules->gearMax('lift'), $rules->gearMax('hoist'), $rules->gearMax('toolkit'), $rules->gearMax('bays'), $rules->gearMax('mechanics')])->toBe([2, 3, 2, 2, 4, 4]);
    });

    it('asks a fee of the jobs on tires where there is no tire machine, and of nothing else', function () {
        $rules = app(Rules::class);

        expect($rules->gearFeeCents(WorkshopGear::start(), 'tires'))->toBe(GEAR_TIRE_FEE_CENTS)
            ->and($rules->gearFeeCents(new WorkshopGear(tireMachine: 1), 'tires'))->toBe(0)
            ->and($rules->gearFeeCents(WorkshopGear::start(), 'suspension'))->toBe(0)
            ->and($rules->gearFeeCents(WorkshopGear::start(), 'cooling'))->toBe(0);
    });

    it('fits two more jobs in the queue for every mechanic at work after the first', function () {
        $rules = app(Rules::class);

        expect(array_map(fn (int $workers): int => $rules->workshopMaxQueue($workers), [1, 2, 3, 4]))->toBe([4, 6, 8, 10])
            ->and($rules->workshopMaxQueue())->toBe(4);
    });
});

describe('buying', function () {
    it('requires a token', function () {
        $this->postJson('/api/v1/garage/equipment', ['item' => 'lift', 'request_id' => 'a'])->assertUnauthorized();
    });

    it('shows a workshop that bought nothing: one bay, one mechanic, no equipment, and what the first of each costs', function () {
        [$driver] = gearDriver();

        gearGarage($driver)->assertOk()
            ->assertJsonPath('data.workshop.queue_max', 4)
            ->assertJsonPath('data.workshop.gear.workers', 1)
            ->assertJsonPath('data.workshop.gear.bays', ['count' => 1, 'max' => 4, 'next_price_cents' => 200_000])
            ->assertJsonPath('data.workshop.gear.mechanics', ['count' => 1, 'max' => 4, 'next_price_cents' => 180_000])
            ->assertJsonPath('data.workshop.gear.items.0', [
                'key' => 'tire_machine', 'slots' => ['tires'], 'level' => 0, 'max_level' => 2, 'name' => 'none', 'time_ppm' => 1_000_000, 'fee_cents' => 3_000,
                'next' => ['name' => 'manual', 'time_ppm' => 500_000, 'price_cents' => 40_000],
            ])
            ->assertJsonPath('data.workshop.gear.items.3.key', 'toolkit')
            ->assertJsonPath('data.workshop.gear.items.3.slots', null)
            ->assertJsonPath('data.workshop.gear.items.3.next.price_cents', 60_000);
    });

    it('sells the next level, takes the price and shows the one after it', function () {
        [$driver, $run] = gearDriver(100_000);

        buyGear($driver, 'tire_machine')
            ->assertCreated()
            ->assertJsonPath('repeated', false)
            ->assertJsonPath('data.workshop.gear.items.0.level', 1)
            ->assertJsonPath('data.workshop.gear.items.0.name', 'manual')
            ->assertJsonPath('data.workshop.gear.items.0.fee_cents', 0)
            ->assertJsonPath('data.workshop.gear.items.0.next', ['name' => 'automatic', 'time_ppm' => 350_000, 'price_cents' => 180_000]);

        expect(cashOf($run))->toBe(60_000);

        // The automatic machine costs $1 800, which the driver does not have; nothing changes.
        buyGear($driver, 'tire_machine')->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');
        expect(cashOf($run))->toBe(60_000)->and(CityWorkshop::query()->value('tire_machine'))->toBe(1);
    });

    it('sells every item up to its highest level and no further', function () {
        [$driver, $run] = gearDriver(10_000_000);

        buyGearTimes($driver, 'lift', 3);
        buyGearTimes($driver, 'hoist', 2);
        buyGearTimes($driver, 'toolkit', 2);
        buyGearTimes($driver, 'tire_machine', 2);

        // $14 450 for all of it.
        expect(cashOf($run))->toBe(10_000_000 - 1_445_000);

        foreach (WorkshopGear::ITEMS as $item) {
            buyGear($driver, $item)->assertStatus(422)->assertJsonPath('code', 'equipment_max');
        }

        gearGarage($driver)->assertOk()->assertJsonPath('data.workshop.gear.items.1.next', null);
    });

    it('sells bays and mechanics one after the other, and a mechanic works only when he has a bay', function () {
        [$driver] = gearDriver(10_000_000);

        buyGear($driver, 'mechanics')->assertCreated()->assertJsonPath('data.workshop.gear.workers', 1)->assertJsonPath('data.workshop.queue_max', 4);
        buyGear($driver, 'bays')->assertCreated()->assertJsonPath('data.workshop.gear.workers', 2)->assertJsonPath('data.workshop.queue_max', 6);

        buyGearTimes($driver, 'bays', 2);
        buyGearTimes($driver, 'mechanics', 2);
        gearGarage($driver)->assertJsonPath('data.workshop.gear.workers', 4)->assertJsonPath('data.workshop.queue_max', 10);

        buyGear($driver, 'bays')->assertStatus(422)->assertJsonPath('code', 'equipment_max');
        buyGear($driver, 'mechanics')->assertStatus(422)->assertJsonPath('code', 'equipment_max');
    });

    it('buys nothing twice when the request is repeated', function () {
        [$driver, $run] = gearDriver(100_000);

        buyGear($driver, 'tire_machine', 'same')->assertCreated();
        buyGear($driver, 'tire_machine', 'same')->assertOk()->assertJsonPath('repeated', true)->assertJsonPath('data.workshop.gear.items.0.level', 1);

        expect(cashOf($run))->toBe(60_000)
            ->and(LedgerEntry::query()->where('reason', 'workshop_gear')->count())->toBe(1);
    });

    it('refuses an item the workshop does not have', function () {
        [$driver] = gearDriver();

        buyGear($driver, 'compressor')->assertStatus(422)->assertJsonValidationErrors('item');
        $this->postJson('/api/v1/garage/equipment', ['item' => 'lift'], bearerFor($driver))->assertStatus(422)->assertJsonValidationErrors('request_id');
    });

    it('answers in the language of the driver', function () {
        [$driver] = gearDriver(10_000_000);
        buyGearTimes($driver, 'toolkit', 2);

        $this->postJson('/api/v1/garage/equipment', ['item' => 'toolkit', 'request_id' => 'x'], [...bearerFor($driver), 'X-Locale' => 'de'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Diese Ausstattung hat bereits die höchste Stufe.');
    });

    it('keeps the equipment of every city apart: a city the driver bought nothing in has the start state', function () {
        [$driver, $run] = gearDriver();
        buyGear($driver, 'toolkit')->assertCreated();

        $equipment = app(WorkshopEquipment::class);
        $home = app(Rules::class)->homeRegion();

        expect($equipment->gearOf($driver->id, $home)->toolkit)->toBe(1)
            ->and($equipment->gearOf($driver->id, 'another-city'))->toEqual(WorkshopGear::start())
            ->and(CityWorkshop::query()->where('user_id', $driver->id)->pluck('city')->all())->toBe([$home])
            ->and($equipment->gearOf(User::factory()->guest()->create()->id, $home))->toEqual(WorkshopGear::start());
    });
});

describe('the times and the fee of the jobs', function () {
    it('asks the fee of the vulcanizer for a job on tires, and nothing for the other slots', function () {
        [$driver, $run] = gearDriver(100_000);
        $tires = gearStock($driver);
        $exhaust = gearStock($driver, 'sport-exhaust-t2', 'exhaust');

        gearJob($driver, 'mount', $tires->id)->assertCreated()
            ->assertJsonPath('data.workshop.jobs.0.cost_cents', GEAR_TIRE_FEE_CENTS)
            ->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + GEAR_MOUNT_T2_MS);
        gearJob($driver, 'mount', $exhaust->id)->assertCreated()->assertJsonPath('data.workshop.jobs.1.cost_cents', 0);

        expect(cashOf($run))->toBe(97_000);
    });

    it('does not ask the fee where the workshop has a tire machine, and puts the tires on in half the time with a manual one', function () {
        [$driver, $run] = gearDriver(100_000);
        buyGear($driver, 'tire_machine')->assertCreated();
        $tires = gearStock($driver);

        gearJob($driver, 'mount', $tires->id)->assertCreated()
            ->assertJsonPath('data.workshop.jobs.0.cost_cents', 0)
            ->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + 117_600);

        expect(cashOf($run))->toBe(60_000);
    });

    it('uses the lift for the suspension, and the hoist and the lift do nothing for the tires', function () {
        [$driver] = gearDriver();
        buyGearTimes($driver, 'lift', 2);
        buyGearTimes($driver, 'hoist', 2);
        $suspension = gearStock($driver, 'sport-suspension-t1', 'suspension');

        // The two-post lift: 45% of 167 s.
        gearJob($driver, 'mount', $suspension->id)->assertCreated()->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + 74_970);

        $tires = gearStock($driver);
        $second = gearJob($driver, 'mount', $tires->id)->assertCreated();

        expect(gearLength($second, 1))->toBe(GEAR_MOUNT_T2_MS);
    });

    it('speeds up the service and the merging by the toolkit', function () {
        [$driver] = gearDriver();
        buyGearTimes($driver, 'toolkit', 2);
        [$one, $two] = gearPair($driver);

        gearGarage($driver)->assertOk()
            ->assertJsonPath('data.car.service.duration_ms', 705_600)
            ->assertJsonPath('data.inventory.0.merge.duration_ms', 376_320);

        gearJob($driver, 'merge', $one, $two)->assertCreated()->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + 376_320);
    });

    it('shows the price and the time of an upgrade and a repair with the equipment of the workshop, the fee included', function () {
        [$driver, $run] = gearDriver();
        Part::factory()->state(['part_slug' => 'street-tires-t2', 'slot' => 'tires'])->mountedOn($run->car)->create(['wear_ppb' => 500_000_000]);

        // The step of the upgrade of $570 tires costs 35% of the price, and a repair of half the wear half of it; each asks the $30 of the vulcanizer.
        $without = gearGarage($driver)->json('data.car.slots.0.part');

        expect($without['upgrade_next']['cost_cents'])->toBe(19_950 + GEAR_TIRE_FEE_CENTS)
            ->and($without['upgrade_next']['duration_ms'])->toBe(294_000)
            ->and($without['repair_next']['cost_cents'])->toBe(14_250 + GEAR_TIRE_FEE_CENTS)
            ->and($without['repair_next']['duration_ms'])->toBe(117_600);

        buyGearTimes($driver, 'tire_machine', 2);
        $with = gearGarage($driver)->json('data.car.slots.0.part');

        expect($with['upgrade_next']['cost_cents'])->toBe(19_950)
            ->and($with['upgrade_next']['duration_ms'])->toBe(102_900)
            ->and($with['repair_next']['cost_cents'])->toBe(14_250)
            ->and($with['repair_next']['duration_ms'])->toBe(41_160);
    });

    it('leaves the jobs that are queued as they are when equipment is bought, and times the next ones with it', function () {
        [$driver] = gearDriver();
        $first = gearStock($driver, 'sport-exhaust-t2', 'exhaust');
        $second = gearStock($driver, 'sport-suspension-t1', 'suspension');

        gearJob($driver, 'mount', $first->id)->assertCreated();
        buyGearTimes($driver, 'lift', 3);
        $response = gearJob($driver, 'mount', $second->id)->assertCreated();

        // The exhaust waited for nothing and was queued before the lift came; the suspension is put on with the four-post lift.
        expect(gearLength($response, 0))->toBe(GEAR_MOUNT_T2_MS)
            ->and(gearLength($response, 1))->toBe(59_976);
    });

    it('gives the fee back when a job that waits for the mechanic is cancelled', function () {
        [$driver, $run] = gearDriver(100_000);
        $exhaust = gearStock($driver, 'sport-exhaust-t2', 'exhaust');
        $tires = gearStock($driver);

        gearJob($driver, 'mount', $exhaust->id)->assertCreated();
        $queued = gearJob($driver, 'mount', $tires->id)->assertCreated()->assertJsonPath('data.workshop.jobs.1.status', 'queued');
        expect(cashOf($run))->toBe(97_000);

        test()->deleteJson('/api/v1/garage/jobs/'.$queued->json('data.workshop.jobs.1.id'), [], bearerFor($driver))->assertOk();

        expect(cashOf($run))->toBe(100_000);
    });
});

describe('more mechanics', function () {
    it('lets a merge go on beside the putting on of a part when there is a second mechanic at work', function () {
        [$driver] = gearDriver();
        buyGear($driver, 'bays')->assertCreated();
        buyGear($driver, 'mechanics')->assertCreated()->assertJsonPath('data.workshop.gear.workers', 2);
        [$one, $two] = gearPair($driver);
        $exhaust = gearStock($driver, 'sport-exhaust-t2', 'exhaust');

        gearJob($driver, 'mount', $exhaust->id)->assertCreated();
        gearJob($driver, 'merge', $one, $two)->assertCreated()
            ->assertJsonPath('data.workshop.jobs.1.status', 'working')
            ->assertJsonPath('data.workshop.jobs.1.start_ms', RUN_T0)
            ->assertJsonPath('data.workshop.jobs.1.finish_ms', RUN_T0 + GEAR_MERGE_T2_MS);
    });

    it('moves the jobs that wait up to the mechanic who has just come', function () {
        [$driver] = gearDriver();
        [$one, $two] = gearPair($driver);
        $exhaust = gearStock($driver, 'sport-exhaust-t2', 'exhaust');

        gearJob($driver, 'mount', $exhaust->id)->assertCreated();
        gearJob($driver, 'merge', $one, $two)->assertCreated()->assertJsonPath('data.workshop.jobs.1.start_ms', RUN_T0 + GEAR_MOUNT_T2_MS);

        // A bay alone changes nothing: a mechanic needs one. With the mechanic the merge does not wait any more.
        buyGear($driver, 'bays')->assertCreated()->assertJsonPath('data.workshop.jobs.1.status', 'queued');
        buyGear($driver, 'mechanics')->assertCreated()
            ->assertJsonPath('data.workshop.jobs.1.status', 'working')
            ->assertJsonPath('data.workshop.jobs.1.start_ms', RUN_T0)
            ->assertJsonPath('data.workshop.jobs.1.finish_ms', RUN_T0 + GEAR_MERGE_T2_MS);
    });

    it('never puts a car in two jobs at once: the putting on of a part waits for the taking off of the old one', function () {
        [$driver, $run] = gearDriver();
        buyGear($driver, 'bays')->assertCreated();
        buyGear($driver, 'mechanics')->assertCreated();
        Part::factory()->state(['part_slug' => 'street-tires-t1', 'slot' => 'tires'])->mountedOn($run->car)->create();
        $tires = gearStock($driver);

        $response = gearJob($driver, 'mount', $tires->id)->assertCreated();
        $unmount = $response->json('data.workshop.jobs.0');
        $mount = $response->json('data.workshop.jobs.1');

        expect($unmount['kind'])->toBe('unmount')
            ->and($mount['kind'])->toBe('mount')
            ->and($mount['start_ms'])->toBe($unmount['finish_ms']);
    });

    it('fits six jobs in the queue of two mechanics and not seven, where one mechanic fits four', function () {
        [$driver] = gearDriver();

        for ($i = 0; $i < 4; $i++) {
            gearJob($driver, 'service')->assertCreated();
        }

        gearJob($driver, 'service')->assertStatus(422)->assertJsonPath('code', 'queue_full');

        buyGear($driver, 'bays')->assertCreated();
        buyGear($driver, 'mechanics')->assertCreated();

        gearJob($driver, 'service')->assertCreated();
        gearJob($driver, 'service')->assertCreated();
        gearJob($driver, 'service')->assertStatus(422)->assertJsonPath('code', 'queue_full');
    });

    it('moves a job up to a mechanic who is free when the job at work is cancelled', function () {
        [$driver] = gearDriver();
        buyGear($driver, 'bays')->assertCreated();
        buyGear($driver, 'mechanics')->assertCreated();
        [$one, $two] = gearPair($driver);
        [$three, $four] = gearPair($driver);
        $exhaust = gearStock($driver, 'sport-exhaust-t2', 'exhaust');

        $mount = gearJob($driver, 'mount', $exhaust->id)->assertCreated()->json('data.workshop.jobs.0.id');
        gearJob($driver, 'merge', $one, $two)->assertCreated();
        // Both mechanics are busy: the second merge waits for the mounting, which ends first.
        gearJob($driver, 'merge', $three, $four)->assertCreated()->assertJsonPath('data.workshop.jobs.2.start_ms', RUN_T0 + GEAR_MOUNT_T2_MS);

        test()->deleteJson("/api/v1/garage/jobs/{$mount}", [], bearerFor($driver))->assertOk()
            ->assertJsonCount(2, 'data.workshop.jobs')
            ->assertJsonPath('data.workshop.jobs.1.status', 'working')
            ->assertJsonPath('data.workshop.jobs.1.start_ms', RUN_T0);
    });

    it('carries out the jobs of two mechanics in the order of the clock when the driver comes back late', function () {
        [$driver, $run] = gearDriver();
        buyGear($driver, 'bays')->assertCreated();
        buyGear($driver, 'mechanics')->assertCreated();
        [$one, $two] = gearPair($driver);
        $exhaust = gearStock($driver, 'sport-exhaust-t2', 'exhaust');

        gearJob($driver, 'mount', $exhaust->id)->assertCreated();
        gearJob($driver, 'merge', $one, $two)->assertCreated();

        // The mounting is done at 235 s and the merge at 470 s: between them one is done and the other is not.
        clockAt(GEAR_MOUNT_T2_MS + 1_000);
        gearGarage($driver)->assertOk()
            ->assertJsonCount(1, 'data.workshop.jobs')
            ->assertJsonPath('data.workshop.jobs.0.kind', 'merge')
            ->assertJsonPath('data.car.slots.6.part.slug', 'sport-exhaust-t2');

        clockAt(GEAR_MERGE_T2_MS + 1_000);
        gearGarage($driver)->assertOk()->assertJsonCount(0, 'data.workshop.jobs');

        expect(WorkshopJob::query()->where('status', '!=', WorkshopJob::STATUS_DONE)->count())->toBe(0)->and($run->refresh()->stop_reason)->not->toBe('workshop');
    });
});
