<?php

declare(strict_types=1);

use App\Content\TutorialSteps;
use App\Models\Car;
use App\Models\Part;
use App\Models\Travel;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WorkshopJob;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/** The steps of the first day and week as the content has them, one by one: what each gives, waits for and pays. */
function weekGet(User $driver, array $headers = []): TestResponse
{
    return test()->getJson('/api/v1/tutorial', [...bearerFor($driver), ...$headers]);
}

/** A driver with a car that does not break down, the steps before this one done, and the wallet in place. */
function weekDriver(string $step, array $run = []): array
{
    [$driver, $car] = driverWithRun(['seed' => str_repeat('0', 32), ...$run]);
    (new Ledger)->ensureWallet($driver->id);

    foreach (array_column(app(TutorialSteps::class)->all(), 'key') as $earlier) {
        if ($earlier === $step) {
            break;
        }

        TutorialProgress::factory()->create(['user_id' => $driver->id, 'step_key' => $earlier, 'state' => TutorialProgress::STATE_DONE, 'completed_at' => now()]);
    }

    return [$driver, $car];
}

afterEach(fn () => Carbon::setTestNow());

describe('the first day', function () {
    it('gives the off-road tires when the car has arrived, and is done when they are on', function () {
        [$driver, $run] = weekDriver('offroad');
        $travel = Travel::factory()->create(['user_id' => $driver->id, 'race_run_id' => $run->id, 'arrive_ms' => RUN_T0 + 1_800_000]);

        // The car is on the road: the goal is in front of the driver, and says what it waits for.
        weekGet($driver, ['X-Locale' => 'pl'])->assertJsonPath('data.current', null)->assertJsonPath('data.goal.key', 'offroad')->assertJsonPath('data.goal.waiting', 'Czeka, aż auto dojedzie do Kruszyna');
        expect(Part::query()->where('user_id', $driver->id)->count())->toBe(0);

        $travel->update(['status' => Travel::STATUS_ARRIVED]);
        weekGet($driver)->assertJsonPath('data.current.key', 'offroad')->assertJsonPath('data.goal.waiting', null)->assertJsonPath('data.goal.target', 'mount');

        $gift = Part::query()->where('user_id', $driver->id)->get();
        expect($gift)->toHaveCount(1)->and($gift->first()->part_slug)->toBe('offroad-tires-t2')->and($gift->first()->rarity)->toBe(1);

        $car = Car::query()->findOrFail($run->car_id);
        $gift->first()->forceFill(['car_id' => $car->id, 'mounted_slot' => 'tires'])->save();

        test()->postJson('/api/v1/tutorial/reply', [], bearerFor($driver))->assertJsonPath('data.goal.key', 'rain')->assertJsonPath('data.done', 11);
    });

    it('waits for rain and a level 6 car before it gives the rain tires', function () {
        [$driver, $run] = weekDriver('rain');

        // Dry weather and a car of level 3: the goal says what it waits for, in the language of the driver.
        weekGet($driver, ['X-Locale' => 'en'])->assertJsonPath('data.current', null)->assertJsonPath('data.goal.waiting', 'Waits for rain on the forecast and a level 6 car');

        $run->update(['config' => [...$run->config, 'weather' => ['seed' => 'test-region', 'chances_ppm' => ['rain' => 1_000_000]]]]);
        weekGet($driver)->assertJsonPath('data.current', null);

        Car::query()->whereKey($run->car_id)->update(['level' => 6]);
        weekGet($driver)->assertJsonPath('data.current.key', 'rain')->assertJsonPath('data.goal.waiting', null);

        expect(Part::query()->where('user_id', $driver->id)->pluck('part_slug')->all())->toBe(['rain-tires-t3']);
    });

    it('gives two uncommon brakes, makes the merge free and sure, and is done with a rare part', function () {
        [$driver] = weekDriver('merge');

        weekGet($driver)->assertJsonPath('data.current.key', 'merge')->assertJsonPath('data.goal.target', 'merge');

        $brakes = Part::query()->where('user_id', $driver->id)->get();
        expect($brakes)->toHaveCount(2)->and($brakes->pluck('part_slug')->unique()->all())->toBe(['disc-brakes-t2'])->and($brakes->pluck('rarity')->unique()->all())->toBe([2]);

        test()->postJson('/api/v1/garage/jobs', ['kind' => 'merge', 'part' => $brakes[0]->id, 'with' => $brakes[1]->id], bearerFor($driver))->assertCreated();

        $job = WorkshopJob::query()->where('user_id', $driver->id)->firstOrFail();
        expect($job->scripted)->toBeTrue()->and($job->cost_cents)->toBe(0);

        clockAt(60_000);
        weekGet($driver)->assertJsonPath('data.goal.key', 'exchange');

        $rare = Part::query()->where('user_id', $driver->id)->get();
        expect($rare)->toHaveCount(1)->and($rare->first()->rarity)->toBe(3)->and(count($rare->first()->affixes))->toBeGreaterThanOrEqual(1);
    });

    it('waits for the cash of the exchange, shows the listing of the tutorial, and pays $300 when the car is bought', function () {
        [$driver] = weekDriver('exchange');

        weekGet($driver, ['X-Locale' => 'en'])->assertJsonPath('data.current', null)->assertJsonPath('data.goal.waiting', 'Waits until you have $450');

        (new Ledger)->post($driver->id, 45_000, 'gift', 'week-money');
        weekGet($driver)->assertJsonPath('data.current.key', 'exchange')->assertJsonPath('data.goal.reward.cash_cents', 30_000);

        $listing = test()->getJson('/api/v1/market', bearerFor($driver))->json('data.exchange.listings.0');
        expect($listing['key'])->toBe('tutorial')->and($listing['price_cents'])->toBe(45_000);

        test()->postJson('/api/v1/market/exchange/tutorial/buy', [], bearerFor($driver))->assertSuccessful();

        weekGet($driver)->assertJsonPath('data.done', 14)->assertJsonPath('data.rewarded.0.key', 'exchange')->assertJsonPath('data.rewarded.0.cash_cents', 30_000);
        expect(Wallet::query()->find($driver->id)->cash_cents)->toBe(30_000);
    });
});

describe('the first week', function () {
    it('lets the driver leave the e-mail for later, and goes on to the garage when the cash and the level are there', function () {
        [$driver] = weekDriver('profile');

        weekGet($driver, ['X-Locale' => 'pl'])->assertJsonPath('data.current.key', 'profile')->assertJsonPath('data.current.optional', true)->assertJsonPath('data.current.decline', 'Nie teraz');
        test()->postJson('/api/v1/tutorial/decline', [], bearerFor($driver))->assertOk()
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('data.goal.key', 'network')
            ->assertJsonPath('data.done', 19);

        expect(Wallet::query()->find($driver->id)->cash_cents)->toBe(0);

        // $5 000 and the experience of a level that opens Kruszyn: the garage of the home city is the one the driver has.
        (new Ledger)->post($driver->id, 500_000, 'gift', 'week-money');
        (new Ledger)->grantXp($driver->id, 400);
        weekGet($driver)->assertJsonPath('data.current.key', 'network')->assertJsonPath('data.goal.target', 'garages');
    });
});
