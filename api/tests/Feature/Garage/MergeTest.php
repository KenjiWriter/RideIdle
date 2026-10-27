<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Domain\Run\Rarity;
use App\Domain\Run\Rng;
use App\Models\MergeAttempt;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

// Street tires of tier 2 cost $570. An attempt of merging two of them costs a tenth of that ($57), takes twice what putting them on takes
// (120 000 x 2 x 0.98 x 2 = 470 400 ms) and gives a quarter of the price back as scrap when it fails ($142.50). The chance of making an uncommon one is
// 80% and a percentage point for the mechanic of level 1: 81%.
const MERGE_COST_CENTS = 5_700;
const MERGE_MS = 470_400;
const MERGE_SCRAP_CENTS = 14_250;

afterEach(fn () => Carbon::setTestNow());

/** A driver at T0 with a car that drives (the seed is fixed, so it does not break down in the middle of a test) and cash to merge with. */
function mergeDriver(int $cash = 100_000): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);
    (new Ledger)->post($driver->id, $cash, 'gift', "merge-test:{$driver->id}");

    return [$driver, $run];
}

/** A part in the inventory of the driver: street tires of tier 2, common, unless said otherwise. */
function mergeStock(User $driver, array $attributes = []): Part
{
    return Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires', ...$attributes]);
}

function mergeJob(User $driver, int $part, ?int $with): TestResponse
{
    return test()->postJson('/api/v1/garage/jobs', ['kind' => 'merge', 'part' => $part, ...($with === null ? [] : ['with' => $with])], bearerFor($driver));
}

function mergeGarage(User $driver): TestResponse
{
    return test()->getJson('/api/v1/garage', bearerFor($driver));
}

/** A key of the application with which the first attempt of the driver is drawn at this chance: a success, or a failure. */
function mergeKeyFor(int $userId, int $chancePpm, bool $success): string
{
    for ($i = 0; $i < 1_000; $i++) {
        $roll = Rng::roll(hash_hmac('sha256', "merge|{$userId}|0", "merge-test-key-{$i}"), 'merge', 0);

        if (($roll < $chancePpm) === $success) {
            return "merge-test-key-{$i}";
        }
    }

    throw new RuntimeException('No key gives that result.');
}

describe('the rules of merging', function () {
    it('has the chances of the design with the level of the mechanic and the protection from bad luck, and never more than 90%', function () {
        $rules = app(Rules::class);

        // 80, 60, 40 and 25% and a percentage point for the mechanic of level 1.
        expect(array_map(fn (Rarity $to): int => $rules->mergeChancePpm($to, 0), [Rarity::Uncommon, Rarity::Rare, Rarity::Epic, Rarity::Legendary]))->toBe([810_000, 610_000, 410_000, 260_000])
            // Three points for every failure since the last success.
            ->and($rules->mergeChancePpm(Rarity::Legendary, 4))->toBe(380_000)
            ->and($rules->mergeChancePpm(Rarity::Uncommon, 2))->toBe(870_000)
            ->and($rules->mergeChancePpm(Rarity::Uncommon, 50))->toBe(900_000);
    });

    it('prices an attempt at a tenth of the model, takes twice the time of putting the part on, and gives a quarter of the price back as scrap', function () {
        $rules = app(Rules::class);

        expect($rules->mergeCostCents(57_000))->toBe(MERGE_COST_CENTS)
            ->and($rules->mergeJobMs('tires', 2))->toBe(MERGE_MS)
            ->and($rules->mergeScrapCents(57_000))->toBe(MERGE_SCRAP_CENTS);
    });
});

describe('the draw of merging', function () {
    it('gives the chances that the design asks for, within a percentage point, over 100 000 attempts of every rarity', function () {
        $rules = app(Rules::class);

        foreach ([Rarity::Uncommon, Rarity::Rare, Rarity::Epic, Rarity::Legendary] as $to) {
            $chance = $rules->mergeChancePpm($to, 0);
            $successes = 0;

            for ($attempt = 0; $attempt < 100_000; $attempt++) {
                $successes += Rng::roll(hash_hmac('sha256', "merge|7|{$attempt}", 'distribution-key'), 'merge', 0) < $chance ? 1 : 0;
            }

            expect(abs($successes / 100_000 - $chance / 1_000_000))->toBeLessThan(0.01);
        }
    });

    it('makes the protection from bad luck shorten the wait: the legendary part takes about 3.2 attempts and not 3.8', function () {
        $rules = app(Rules::class);
        $attempts = 0;
        $made = 0;
        $failures = 0;

        for ($roll = 0; $made < 20_000; $roll++) {
            $attempts++;
            $chance = $rules->mergeChancePpm(Rarity::Legendary, $failures);

            if (Rng::roll(hash_hmac('sha256', "merge|9|{$roll}", 'pity-key'), 'merge', 0) < $chance) {
                $made++;
                $failures = 0;
            } else {
                $failures++;
            }
        }

        // 1 / 0.26 is 3.85 without the protection; with it the exact value is 3.18.
        expect($attempts / $made)->toBeGreaterThan(3.0)->toBeLessThan(3.35);
    });
});

describe('merging two parts', function () {
    it('is paid when it is queued, reserves both parts, and does not stop the car', function () {
        [$driver, $run] = mergeDriver();
        $base = mergeStock($driver);
        $other = mergeStock($driver);

        mergeJob($driver, $base->id, $other->id)->assertCreated()
            ->assertJsonPath('data.workshop.jobs.0.kind', 'merge')
            ->assertJsonPath('data.workshop.jobs.0.status', 'working')
            ->assertJsonPath('data.workshop.jobs.0.cost_cents', MERGE_COST_CENTS)
            ->assertJsonPath('data.workshop.jobs.0.merge_to', 'uncommon')
            ->assertJsonPath('data.workshop.jobs.0.merge_with.id', $other->id)
            ->assertJsonPath('data.workshop.jobs.0.part.id', $base->id)
            ->assertJsonPath('data.workshop.jobs.0.finish_ms', RUN_T0 + MERGE_MS)
            // The parts are not on the car, so it keeps driving.
            ->assertJsonPath('data.car.run.stop_reason', null)
            // And what is reserved offers nothing to merge.
            ->assertJsonPath('data.inventory.0.merge', null)
            ->assertJsonPath('data.inventory.1.merge', null);

        expect(cashOf($run))->toBe(100_000 - MERGE_COST_CENTS);

        mergeJob($driver, $base->id, mergeStock($driver)->id)->assertStatus(422)->assertJsonPath('code', 'part_busy');
        mergeJob($driver, mergeStock($driver)->id, $other->id)->assertStatus(422)->assertJsonPath('code', 'part_busy');
        test()->postJson('/api/v1/garage/jobs', ['kind' => 'mount', 'part' => $other->id], bearerFor($driver))->assertStatus(422)->assertJsonPath('code', 'part_busy');
    });

    it('makes the part of the next rarity when the draw succeeds: the base part stays, with its upgrade, and the other is used up', function () {
        [$driver] = mergeDriver();
        $base = mergeStock($driver, ['upgrade_level' => 2]);
        $other = mergeStock($driver, ['upgrade_level' => 1]);
        config(['app.key' => mergeKeyFor($driver->id, 810_000, true)]);

        mergeJob($driver, $base->id, $other->id)->assertCreated();
        clockAt(MERGE_MS + 1_000);

        mergeGarage($driver)->assertOk()
            ->assertJsonPath('data.workshop.jobs', [])
            ->assertJsonPath('data.merges.0.success', true)
            ->assertJsonPath('data.merges.0.to', 'uncommon')
            ->assertJsonPath('data.merges.0.chance_ppm', 810_000)
            ->assertJsonPath('data.merges.0.scrap_cents', 0)
            ->assertJsonPath('data.merges.0.part.id', $base->id)
            ->assertJsonPath('data.merges.0.part.rarity', 'uncommon')
            ->assertJsonPath('data.inventory', fn (array $inventory): bool => count($inventory) === 1 && $inventory[0]['id'] === $base->id && $inventory[0]['upgrade'] === 2);

        expect($base->refresh()->rarity)->toBe(2)
            ->and(Part::query()->find($other->id))->toBeNull()
            ->and(MergeAttempt::query()->count())->toBe(1)
            ->and(MergeAttempt::query()->first()->seed)->toBe(hash_hmac('sha256', "merge|{$driver->id}|0", config('app.key')));
    });

    it('loses both parts when the draw fails, gives the scrap, and raises the chance of the next attempt at that slot and rarity', function () {
        [$driver, $run] = mergeDriver();
        $base = mergeStock($driver);
        $other = mergeStock($driver);
        config(['app.key' => mergeKeyFor($driver->id, 810_000, false)]);

        mergeJob($driver, $base->id, $other->id)->assertCreated();
        clockAt(MERGE_MS + 1_000);

        mergeGarage($driver)->assertOk()
            ->assertJsonPath('data.inventory', [])
            ->assertJsonPath('data.merges.0.success', false)
            ->assertJsonPath('data.merges.0.scrap_cents', MERGE_SCRAP_CENTS)
            ->assertJsonPath('data.merges.0.part', null);

        expect(Part::query()->whereIn('id', [$base->id, $other->id])->count())->toBe(0)
            // The price of the attempt went at the queue, and the scrap came with the failure: $1 000 less $57 plus $142.50.
            ->and(cashOf($run))->toBe(100_000 - MERGE_COST_CENTS + MERGE_SCRAP_CENTS);

        // Two new parts of the same slot and rarity: three points more, one failure since the last success.
        mergeStock($driver);
        mergeStock($driver);
        mergeStock($driver, ['slot' => 'suspension', 'part_slug' => 'sport-suspension-t1']);

        mergeGarage($driver)->assertOk()
            ->assertJsonPath('data.inventory.0.merge.chance_ppm', 840_000)
            ->assertJsonPath('data.inventory.0.merge.pity_ppm', 30_000)
            ->assertJsonPath('data.inventory.0.merge.to', 'uncommon')
            ->assertJsonPath('data.inventory.0.merge.cost_cents', MERGE_COST_CENTS)
            ->assertJsonPath('data.inventory.0.merge.duration_ms', MERGE_MS)
            ->assertJsonPath('data.inventory.0.merge.scrap_cents', MERGE_SCRAP_CENTS)
            // The protection is counted for the slot: the suspension has none, and no partner.
            ->assertJsonPath('data.inventory.2.merge.chance_ppm', 810_000)
            ->assertJsonPath('data.inventory.2.merge.with', []);
    });

    it('says which parts a part can be merged with, and nothing for a legendary one', function () {
        [$driver] = mergeDriver();
        $first = mergeStock($driver);
        $second = mergeStock($driver);
        $rare = mergeStock($driver, ['rarity' => 3]);
        $legendary = mergeStock($driver, ['rarity' => 5]);

        $inventory = mergeGarage($driver)->assertOk()->json('data.inventory');
        $byId = collect($inventory)->keyBy('id');

        expect($byId[$first->id]['merge']['with'])->toBe([$second->id])
            ->and($byId[$second->id]['merge']['with'])->toBe([$first->id])
            ->and($byId[$rare->id]['merge'])->toMatchArray(['to' => 'epic', 'chance_ppm' => 410_000, 'with' => []])
            ->and($byId[$legendary->id]['merge'])->toBeNull();
    });

    it('is queued behind the job the mechanic is at, and the car stands only for its own jobs', function () {
        [$driver, $run] = mergeDriver();
        $base = mergeStock($driver);
        $other = mergeStock($driver);
        $tires = mergeStock($driver);

        test()->postJson('/api/v1/garage/jobs', ['kind' => 'mount', 'part' => $tires->id], bearerFor($driver))->assertCreated();
        $queued = mergeJob($driver, $base->id, $other->id)->assertCreated();

        // The mechanic is at the mounting: the car stands for it, and the merge waits for it to end.
        expect($queued->json('data.workshop.jobs.1.status'))->toBe('queued')
            ->and($queued->json('data.workshop.jobs.1.start_ms'))->toBe($queued->json('data.workshop.jobs.0.finish_ms'))
            ->and($queued->json('data.car.run.stop_reason'))->toBe('workshop');

        // The mounting is done and the merge is at work: the car drives again while the mechanic is at the parts.
        clockAt($queued->json('data.workshop.jobs.0.finish_ms') - RUN_T0 + 1_000);
        mergeGarage($driver)->assertOk()
            ->assertJsonPath('data.workshop.jobs.0.kind', 'merge')
            ->assertJsonPath('data.workshop.jobs.0.status', 'working')
            ->assertJsonPath('data.car.run.stop_reason', null);
    });

    it('refuses what cannot be merged: the same part, parts that differ, a part on a car, the highest rarity, another driver\'s part, and a driver who cannot pay', function () {
        [$driver, $run] = mergeDriver();
        $base = mergeStock($driver);
        $other = mergeStock($driver);

        mergeJob($driver, $base->id, $base->id)->assertStatus(422)->assertJsonPath('code', 'merge_parts_differ');
        mergeJob($driver, $base->id, mergeStock($driver, ['rarity' => 2])->id)->assertStatus(422)->assertJsonPath('code', 'merge_parts_differ');
        mergeJob($driver, $base->id, mergeStock($driver, ['slot' => 'suspension', 'part_slug' => 'sport-suspension-t1'])->id)->assertStatus(422)->assertJsonPath('code', 'merge_parts_differ');
        mergeJob($driver, $base->id, mergeStock($driver, ['part_slug' => 'street-tires-t1'])->id)->assertStatus(422)->assertJsonPath('code', 'merge_parts_differ');
        mergeJob($driver, mergeStock($driver, ['rarity' => 5])->id, mergeStock($driver, ['rarity' => 5])->id)->assertStatus(422)->assertJsonPath('code', 'merge_max');

        $mounted = Part::factory()->state(['part_slug' => 'street-tires-t2', 'slot' => 'tires'])->mountedOn($run->car)->create();
        mergeJob($driver, $base->id, $mounted->id)->assertStatus(422)->assertJsonPath('code', 'part_mounted');

        $strangers = mergeStock(User::factory()->guest()->create());
        mergeJob($driver, $base->id, $strangers->id)->assertStatus(422)->assertJsonPath('code', 'part_not_found');

        test()->postJson('/api/v1/garage/jobs', ['kind' => 'merge', 'part' => $base->id], bearerFor($driver))->assertStatus(422)->assertJsonValidationErrors('with');

        expect(WorkshopJob::query()->count())->toBe(0)->and(cashOf($run))->toBe(100_000);

        // Not enough cash: nothing is queued, nothing is charged.
        [$poor] = mergeDriver(MERGE_COST_CENTS - 1);
        mergeJob($poor, ($one = mergeStock($poor))->id, mergeStock($poor)->id)->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');
        expect(WorkshopJob::query()->where('user_id', $poor->id)->count())->toBe(0);
    });

    it('gives the price back when a merge that waits is cancelled, and loses it when the mechanic is at it', function () {
        [$driver, $run] = mergeDriver();
        $tires = mergeStock($driver);
        test()->postJson('/api/v1/garage/jobs', ['kind' => 'mount', 'part' => $tires->id], bearerFor($driver))->assertCreated();

        $base = mergeStock($driver);
        $other = mergeStock($driver);
        $waiting = mergeJob($driver, $base->id, $other->id)->assertCreated()->assertJsonPath('data.workshop.jobs.1.status', 'queued');
        $spent = cashOf($run);

        test()->deleteJson('/api/v1/garage/jobs/'.$waiting->json('data.workshop.jobs.1.id'), [], bearerFor($driver))->assertOk();
        expect(cashOf($run))->toBe($spent + MERGE_COST_CENTS)
            // Both parts are free again, and can be merged again (and paid again).
            ->and(Part::query()->whereIn('id', [$base->id, $other->id])->count())->toBe(2);

        // At work: no mounting in front, so the mechanic starts at once.
        [$second, $secondRun] = mergeDriver();
        $at = mergeJob($second, ($a = mergeStock($second))->id, ($b = mergeStock($second))->id)->assertCreated()->assertJsonPath('data.workshop.jobs.0.status', 'working');
        test()->deleteJson('/api/v1/garage/jobs/'.$at->json('data.workshop.jobs.0.id'), [], bearerFor($second))->assertOk();

        expect(cashOf($secondRun))->toBe(100_000 - MERGE_COST_CENTS)->and(Part::query()->whereIn('id', [$a->id, $b->id])->count())->toBe(2);
    });

    it('draws with the chance it has when the mechanic is done, one attempt after the other', function () {
        [$driver] = mergeDriver(1_000_000);
        $parts = collect(range(1, 4))->map(fn (): Part => mergeStock($driver));

        mergeJob($driver, $parts[0]->id, $parts[1]->id)->assertCreated();
        mergeJob($driver, $parts[2]->id, $parts[3]->id)->assertCreated();
        clockAt(2 * MERGE_MS + 1_000);

        mergeGarage($driver)->assertOk();

        $attempts = MergeAttempt::query()->orderBy('id')->get();

        // The second attempt is drawn from another seed, and counts the failure of the first for its chance.
        expect($attempts)->toHaveCount(2)
            ->and($attempts[0]->chance_ppm)->toBe(810_000)
            ->and($attempts[1]->seed)->not->toBe($attempts[0]->seed)
            ->and($attempts[1]->chance_ppm)->toBe($attempts[0]->success ? 810_000 : 840_000);
    });
});
