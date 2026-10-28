<?php

declare(strict_types=1);

use App\Content\TutorialSteps;
use App\Models\Car;
use App\Models\MergeAttempt;
use App\Models\Part;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WorkshopJob;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * What the step that waits arranges for the first time: jobs of the workshop that are short and paid by the mentor (a merge that also cannot fail), and the car of
 * the tutorial on the exchange. The steps are made up here, so that the tests say what the engine does and not what the content asks for.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function arrStep(string $key, array $overrides = []): array
{
    $text = ['en' => 'Hello. — K.', 'pl' => 'Cześć. — K.', 'fr' => 'Salut. — K.', 'de' => 'Hallo. — K.', 'pt-BR' => 'Oi. — K.'];

    return [
        'key' => $key,
        'phase' => 3,
        'trigger' => 'none',
        'completion' => 'acknowledged',
        'target' => null,
        'goal' => ['en' => 'Do '.$key, 'pl' => 'Zrób '.$key, 'fr' => 'Fais '.$key, 'de' => 'Mach '.$key, 'pt-BR' => 'Faça '.$key],
        'sender' => '+48 555 010 '.str_pad((string) (100 + crc32($key) % 800), 3, '0'),
        'sms' => [$text],
        'reply' => ['en' => 'OK', 'pl' => 'OK', 'fr' => 'OK', 'de' => 'OK', 'pt-BR' => 'OK'],
        'reward' => [],
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $steps
 * @param  array<string, mixed>  $script
 */
function arrTutorial(array $steps, array $script = []): void
{
    app()->instance(TutorialSteps::class, TutorialSteps::fromArray([
        'steps' => $steps,
        'script' => ['first_run_fuel_ml' => 17_000, 'breakdown_lap' => 6, 'first_mount_ms' => 30_000, 'covers_up_to_cents' => 30_000, ...$script],
    ]));
}

function arrTutorialOf(User $driver): TestResponse
{
    return test()->getJson('/api/v1/tutorial', bearerFor($driver));
}

function arrGarage(User $driver): TestResponse
{
    return test()->getJson('/api/v1/garage', bearerFor($driver));
}

function arrJob(User $driver, array $body): TestResponse
{
    return test()->postJson('/api/v1/garage/jobs', $body, bearerFor($driver));
}

/** A driver at T0 whose car does not break down in the middle of a test, with the step already started. */
function arrDriver(int $cash = 0): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);
    (new Ledger)->ensureWallet($driver->id);

    if ($cash > 0) {
        (new Ledger)->post($driver->id, $cash, 'gift', "arranged-test:{$driver->id}");
    }

    arrTutorialOf($driver);

    return [$driver, $run];
}

afterEach(fn () => Carbon::setTestNow());

describe('jobs of the workshop that a step arranges', function () {
    it('makes a merge short, free and sure, and the attempt is settled as sure when the mechanic is done', function () {
        arrTutorial([arrStep('merge', ['arranges' => ['merge'], 'completion' => 'merge_done'])]);
        [$driver] = arrDriver();
        $base = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires']);
        $other = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires']);

        // The quote the driver sees is the arranged one: nothing to pay, at most 30 s, no chance of failing.
        $quote = arrGarage($driver)->assertOk()->json('data.inventory.0.merge');

        expect($quote['cost_cents'])->toBe(0)
            ->and($quote['duration_ms'])->toBeLessThanOrEqual(30_000)
            ->and($quote['chance_ppm'])->toBe(1_000_000)
            ->and($quote['pity_ppm'])->toBe(0);

        arrJob($driver, ['kind' => 'merge', 'part' => $base->id, 'with' => $other->id])->assertCreated();

        $job = WorkshopJob::query()->where('user_id', $driver->id)->firstOrFail();

        expect($job->scripted)->toBeTrue()
            ->and($job->cost_cents)->toBe(0)
            ->and($job->finish_ms - $job->start_ms)->toBeLessThanOrEqual(30_000)
            ->and(Wallet::query()->find($driver->id)->cash_cents)->toBe(0);

        clockAt(60_000);
        arrGarage($driver)->assertOk();

        $attempt = MergeAttempt::query()->where('user_id', $driver->id)->firstOrFail();

        expect($attempt->success)->toBeTrue()
            ->and($attempt->chance_ppm)->toBe(1_000_000)
            ->and($base->refresh()->rarity)->toBe(2);

        arrTutorialOf($driver)->assertJsonPath('data.status', 'finished');
    });

    it('leaves the merge as it is for a driver whose step does not arrange it', function () {
        arrTutorial([arrStep('merge', ['completion' => 'merge_done'])]);
        [$driver] = arrDriver(10_000);
        $base = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires']);
        $other = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires']);

        arrJob($driver, ['kind' => 'merge', 'part' => $base->id, 'with' => $other->id])->assertCreated();

        $job = WorkshopJob::query()->where('user_id', $driver->id)->firstOrFail();

        // A tenth of $570, the real price.
        expect($job->scripted)->toBeFalse()->and($job->cost_cents)->toBe(5_700);
    });

    it('makes an upgrade and a service short and paid by the mentor, up to the sum the script sets', function () {
        arrTutorial([arrStep('upgrade', ['arranges' => ['upgrade', 'service'], 'completion' => 'upgrade_done'])]);
        [$driver, $run] = arrDriver();
        $car = Car::query()->findOrFail($run->car_id);
        $part = Part::factory()->state(['slot' => 'tires', 'part_slug' => 'street-tires-t2'])->mountedOn($car)->create();

        arrJob($driver, ['kind' => 'upgrade', 'part' => $part->id])->assertCreated();

        $job = WorkshopJob::query()->where('user_id', $driver->id)->where('kind', 'upgrade')->firstOrFail();

        // 35% of $570 and the fee of the vulcanizer come to $229.50, which the mentor pays whole.
        expect($job->cost_cents)->toBe(0)->and($job->finish_ms - $job->start_ms)->toBeLessThanOrEqual(30_000);

        clockAt(120_000);
        arrGarage($driver)->assertOk();
        arrJob($driver, ['kind' => 'service'])->assertCreated();

        $service = WorkshopJob::query()->where('user_id', $driver->id)->where('kind', 'service')->firstOrFail();

        expect($service->cost_cents)->toBe(0)->and($service->finish_ms - $service->start_ms)->toBeLessThanOrEqual(30_000);
    });

    it('pays only the part of the cost above the sum the mentor covers', function () {
        arrTutorial([arrStep('upgrade', ['arranges' => ['upgrade'], 'completion' => 'upgrade_done'])], ['covers_up_to_cents' => 10_000]);
        [$driver, $run] = arrDriver(50_000);
        $car = Car::query()->findOrFail($run->car_id);
        $part = Part::factory()->state(['slot' => 'tires', 'part_slug' => 'street-tires-t2'])->mountedOn($car)->create();

        arrJob($driver, ['kind' => 'upgrade', 'part' => $part->id])->assertCreated();

        // $229.50 less $100.
        expect(WorkshopJob::query()->where('user_id', $driver->id)->firstOrFail()->cost_cents)->toBe(12_950);
    });

    it('makes putting new tires on short and without the fee of the vulcanizer, taking the old ones off too', function () {
        arrTutorial([arrStep('offroad', ['arranges' => ['mount', 'unmount'], 'completion' => 'tires_mounted:offroad'])]);
        [$driver, $run] = arrDriver();
        $car = Car::query()->findOrFail($run->car_id);
        Part::factory()->state(['slot' => 'tires', 'part_slug' => 'street-tires-t1'])->mountedOn($car)->create();
        $tires = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'offroad-tires-t2', 'slot' => 'tires']);

        arrJob($driver, ['kind' => 'mount', 'part' => $tires->id])->assertCreated();

        $jobs = WorkshopJob::query()->where('user_id', $driver->id)->get();

        expect($jobs->pluck('kind')->sort()->values()->all())->toBe(['mount', 'unmount'])
            ->and($jobs->sum('cost_cents'))->toBe(0)
            ->and($jobs->every(fn (WorkshopJob $job): bool => $job->finish_ms - $job->start_ms <= 30_000))->toBeTrue();
    });
});

describe('the car of the tutorial on the exchange', function () {
    $listing = ['vehicle' => 'rdzak-gt-2', 'age_years' => 25, 'mileage_km' => 280_000, 'condition_ppm' => 300_000, 'price_cents' => 45_000];

    it('is on the exchange for the step that asks for a bought car, inspected for nothing and bought at its price', function () use ($listing) {
        arrTutorial([arrStep('buy', ['completion' => 'car_bought'])], ['exchange_listing' => $listing]);
        [$driver] = arrDriver(45_000);

        $first = test()->getJson('/api/v1/market', bearerFor($driver))->assertOk()->json('data.exchange.listings.0');

        expect($first['key'])->toBe('tutorial')
            ->and($first['price_cents'])->toBe(45_000)
            ->and($first['vehicle']['slug'])->toBe('rdzak-gt-2')
            ->and($first['word'])->toBe('poor')
            ->and($first['inspected'])->toBeFalse()
            ->and($first['condition'])->toBeNull();

        test()->postJson('/api/v1/market/exchange/tutorial/inspect', [], bearerFor($driver))->assertSuccessful();

        $inspected = test()->getJson('/api/v1/market', bearerFor($driver))->json('data.exchange.listings.0');

        expect($inspected['inspected'])->toBeTrue()
            ->and($inspected['condition']['percent'])->toBe(30)
            // The mentor paid for it.
            ->and(Wallet::query()->find($driver->id)->cash_cents)->toBe(45_000);

        test()->postJson('/api/v1/market/exchange/tutorial/buy', [], bearerFor($driver))->assertSuccessful();

        expect(Car::query()->where('user_id', $driver->id)->count())->toBe(2)
            ->and(Wallet::query()->find($driver->id)->cash_cents)->toBe(0)
            ->and(test()->getJson('/api/v1/market', bearerFor($driver))->json('data.exchange.listings.0.key'))->not->toBe('tutorial');

        arrTutorialOf($driver)->assertJsonPath('data.status', 'finished');
    });

    it('is not there for a driver whose step does not ask for a bought car, nor to buy', function () use ($listing) {
        arrTutorial([arrStep('other')], ['exchange_listing' => $listing]);
        [$driver] = arrDriver(45_000);

        $keys = array_column(test()->getJson('/api/v1/market', bearerFor($driver))->assertOk()->json('data.exchange.listings'), 'key');

        expect($keys)->not->toContain('tutorial');

        test()->postJson('/api/v1/market/exchange/tutorial/buy', [], bearerFor($driver))->assertStatus(422)->assertJsonPath('code', 'listing_gone');
    });
});
