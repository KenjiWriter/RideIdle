<?php

declare(strict_types=1);

use App\Content\TutorialSteps;
use App\Models\Car;
use App\Models\CityGarage;
use App\Models\MergeAttempt;
use App\Models\Part;
use App\Models\Travel;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WorkshopJob;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * The engine of the steps of the first day and week: facts with a number after a colon, lists of facts, a gift when a step starts, a step that can be left
 * for later. They run on steps of their own, so that they say what the engine does and not what the content asks for.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function daysStep(string $key, array $overrides = []): array
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

/** @param  list<array<string, mixed>>  $steps */
function theTutorialIs(array $steps): void
{
    app()->instance(TutorialSteps::class, TutorialSteps::fromArray([
        'steps' => $steps,
        'script' => ['first_run_fuel_ml' => 17_000, 'breakdown_lap' => 6, 'first_mount_ms' => 30_000, 'covers_up_to_cents' => 30_000],
    ]));
}

/** @param  array<string, string>  $headers */
function daysGet(User $driver, array $headers = []): TestResponse
{
    return test()->getJson('/api/v1/tutorial', [...bearerFor($driver), ...$headers]);
}

function daysReply(User $driver): TestResponse
{
    return test()->postJson('/api/v1/tutorial/reply', [], bearerFor($driver));
}

function daysSkip(User $driver): TestResponse
{
    return test()->postJson('/api/v1/tutorial/skip', [], bearerFor($driver));
}

function daysCashOf(User $driver): int
{
    return (int) Wallet::query()->find($driver->id)?->cash_cents;
}

function daysCash(User $driver, int $cents): void
{
    (new Ledger)->post($driver->id, $cents, 'gift', 'days-money:'.$driver->id.':'.$cents);
}

describe('the facts of the first day and week', function () {
    it('waits for every fact of a list', function () {
        theTutorialIs([daysStep('both', ['trigger' => ['cash:50', 'car_level:6']])]);
        [$driver, $run] = driverWithRun();
        daysCash($driver, 6_000);

        daysGet($driver)->assertOk()->assertJsonPath('data.current', null);

        Car::query()->whereKey($run->car_id)->update(['level' => 6]);

        daysGet($driver)->assertOk()->assertJsonPath('data.current.key', 'both');
    });

    it('reads a number after a colon: the cash in dollars, and the level of the car', function () {
        theTutorialIs([daysStep('rich', ['trigger' => 'cash:450'])]);
        [$driver] = driverWithRun();
        daysCash($driver, 44_999);

        daysGet($driver)->assertJsonPath('data.current', null);

        daysCash($driver, 1);
        daysGet($driver)->assertJsonPath('data.current.key', 'rich');
    });

    it('puts the gift in the inventory when the step starts, once, with the affixes of its rarity', function () {
        theTutorialIs([daysStep('gifted', ['gift' => ['part' => 'disc-brakes-t2', 'rarity' => 'uncommon', 'count' => 2]])]);
        [$driver] = driverWithRun();

        daysGet($driver)->assertJsonPath('data.current.key', 'gifted');
        daysGet($driver)->assertJsonPath('data.current.key', 'gifted');

        $parts = Part::query()->where('user_id', $driver->id)->get();

        expect($parts)->toHaveCount(2)
            ->and($parts->pluck('rarity')->unique()->all())->toBe([2])
            ->and($parts->pluck('slot')->unique()->all())->toBe(['brakes'])
            ->and(count($parts->first()->affixes))->toBeGreaterThanOrEqual(1);
    });

    it('knows the tires on the car by their kind', function () {
        theTutorialIs([daysStep('gravel', ['completion' => 'tires_mounted:offroad'])]);
        [$driver, $run] = driverWithRun();
        $car = Car::query()->findOrFail($run->car_id);
        Part::factory()->state(['slot' => 'tires', 'part_slug' => 'street-tires-t1'])->mountedOn($car)->create();

        daysGet($driver)->assertJsonPath('data.current.key', 'gravel');
        daysReply($driver)->assertJsonPath('data.current.key', 'gravel');

        Part::query()->where('car_id', $car->id)->update(['part_slug' => 'offroad-tires-t2']);

        daysGet($driver)->assertJsonPath('data.status', 'finished')->assertJsonPath('data.done', 1);
    });

    it('sees rain on the forecast of the track of the car', function () {
        theTutorialIs([daysStep('wet', ['trigger' => 'rain_forecast'])]);
        [$driver, $run] = driverWithRun();

        daysGet($driver)->assertJsonPath('data.current', null);

        $run->update(['config' => [...$run->config, 'weather' => ['seed' => 'test-region', 'chances_ppm' => ['rain' => 1_000_000]]]]);

        daysGet($driver)->assertJsonPath('data.current.key', 'wet');
    });

    it('counts only what was done since the step started', function () {
        theTutorialIs([daysStep('merge', ['completion' => 'merge_done'])]);
        [$driver, $run] = driverWithRun();
        $attempt = fn (string $when) => MergeAttempt::query()->create([
            'user_id' => $driver->id, 'job_id' => WorkshopJob::factory()->create(['car_id' => $run->car_id, 'status' => WorkshopJob::STATUS_DONE])->id, 'slot' => 'tires', 'tier' => 1, 'to_rarity' => 2,
            'part_slug' => 'street-tires-t1', 'part_id' => null, 'chance_ppm' => 1_000_000, 'roll_ppm' => 1, 'success' => true, 'scrap_cents' => 0, 'seed' => 's', 'finished_ms' => 1,
            'created_at' => $when,
        ]);

        // A merge from before the step was asked for does not end it.
        $attempt(now()->subDay()->toDateTimeString());
        daysGet($driver)->assertJsonPath('data.current.key', 'merge');
        daysReply($driver)->assertJsonPath('data.current.key', 'merge');

        $attempt(now()->addMinute()->toDateTimeString());
        daysGet($driver)->assertJsonPath('data.status', 'finished');
    });

    it('sees the arrival of the car, a purchase of the workshop, a bought garage and a confirmed e-mail', function () {
        theTutorialIs([
            daysStep('arrived', ['completion' => 'travel_done']),
            daysStep('gear', ['completion' => 'equipment_bought']),
            daysStep('network', ['completion' => 'garage_bought']),
            daysStep('profile', ['completion' => 'email_confirmed']),
        ]);
        [$driver, $run] = driverWithRun();
        $travel = Travel::factory()->create(['user_id' => $driver->id, 'race_run_id' => $run->id]);

        daysGet($driver)->assertJsonPath('data.current.key', 'arrived');
        $travel->update(['status' => Travel::STATUS_ARRIVED]);
        daysGet($driver)->assertJsonPath('data.current.key', 'gear');

        daysReply($driver);
        (new Ledger)->post($driver->id, 1_000, 'gift', 'days-gift');
        (new Ledger)->post($driver->id, -400, 'workshop_gear', 'days-gear');
        daysGet($driver)->assertJsonPath('data.current.key', 'network');

        daysReply($driver);
        (new Ledger)->post($driver->id, -500, 'garage', 'days-garage');
        daysGet($driver)->assertJsonPath('data.current.key', 'profile');

        daysReply($driver);
        daysGet($driver)->assertJsonPath('data.done', 3)->assertJsonPath('data.current.key', 'profile');

        $driver->forceFill(['email_verified_at' => now()])->save();
        daysGet($driver)->assertJsonPath('data.status', 'finished');
    });

    it('opens a city with a track the driver may drive on, where they have no garage yet', function () {
        theTutorialIs([daysStep('network', ['trigger' => 'city_open'])]);
        [$driver] = driverWithRun();

        // Level one: the only city with a track that is open is Złomowo, the home of the first garage.
        foreach (['zlomowo'] as $region) {
            CityGarage::query()->create(['user_id' => $driver->id, 'region' => $region, 'capacity' => 3]);
        }

        daysGet($driver)->assertJsonPath('data.current', null);

        // Level three opens Kruszyn.
        (new Ledger)->ensureWallet($driver->id);
        Wallet::query()->where('user_id', $driver->id)->update(['xp' => 180]);
        daysGet($driver)->assertJsonPath('data.current.key', 'network');
    });

    it('sees that the driver came back after a break', function () {
        theTutorialIs([daysStep('away', ['trigger' => 'returned:30'])]);
        [$driver] = driverWithRun();

        // Active use: the visits are ten minutes apart.
        $driver->forceFill(['previous_seen_at' => now()->subMinutes(10), 'last_seen_at' => now()])->save();
        daysGet($driver)->assertJsonPath('data.current', null);

        $driver->forceFill(['previous_seen_at' => now()->subHours(3), 'last_seen_at' => now()->subMinutes(5)])->save();
        daysGet($driver)->assertJsonPath('data.current.key', 'away');
    });

    it('keeps the visit before the last one when a driver is seen again', function () {
        [$driver] = driverWithRun();
        $driver->forceFill(['last_seen_at' => now()->subHours(5), 'previous_seen_at' => null])->save();
        $before = $driver->last_seen_at->toDateTimeString();

        daysGet($driver)->assertOk();

        $driver->refresh();

        expect($driver->previous_seen_at->toDateTimeString())->toBe($before)
            ->and($driver->last_seen_at->gt($driver->previous_seen_at))->toBeTrue();
    });
});

describe('the goal in front of the driver', function () {
    it('names the step that waits for its trigger, with what it waits for and what it pays', function () {
        theTutorialIs([
            daysStep('one'),
            daysStep('rich', [
                'trigger' => 'cash:450',
                'waiting' => ['en' => 'Save up $450', 'pl' => 'Uzbieraj $450', 'fr' => 'Économise 450 $', 'de' => 'Spare 450 $', 'pt-BR' => 'Junte US$ 450'],
                'target' => 'exchange',
                'reward' => ['cash_cents' => 30_000, 'part' => 'street-tires-t2', 'garage' => 'kruszyn'],
            ]),
        ]);
        [$driver] = driverWithRun();

        daysGet($driver, ['X-Locale' => 'pl'])->assertJsonPath('data.goal.key', 'one')->assertJsonPath('data.goal.title', 'Zrób one')->assertJsonPath('data.goal.waiting', null);
        daysReply($driver)
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('data.goal.key', 'rich')
            ->assertJsonPath('data.goal.number', 2)
            ->assertJsonPath('data.goal.target', 'exchange')
            ->assertJsonPath('data.goal.reward.cash_cents', 30_000)
            ->assertJsonPath('data.goal.reward.part', 'Street tires')
            ->assertJsonPath('data.goal.reward.garage', 'Kruszyn');

        // While the trigger does not hold the goal says what it waits for. The step has no row yet.
        daysGet($driver, ['X-Locale' => 'pl'])->assertJsonPath('data.goal.key', 'rich')->assertJsonPath('data.goal.waiting', 'Uzbieraj $450');

        daysCash($driver, 45_000);
        // Once it has started the goal stays and no longer waits.
        daysGet($driver, ['X-Locale' => 'pl'])->assertJsonPath('data.current.key', 'rich')->assertJsonPath('data.goal.key', 'rich')->assertJsonPath('data.goal.waiting', null);
    });

    it('is gone when the tutorial is over or was skipped', function () {
        theTutorialIs([daysStep('only')]);
        [$driver] = driverWithRun();

        daysGet($driver)->assertJsonPath('data.goal.key', 'only');
        daysReply($driver)->assertJsonPath('data.status', 'finished')->assertJsonPath('data.goal', null);

        [$other] = driverWithRun();
        daysSkip($other)->assertJsonPath('data.status', 'skipped')->assertJsonPath('data.goal', null);
    });
});

describe('a step that can be left for later', function () {
    it('goes on to the next step with no reward, and counts as passed', function () {
        theTutorialIs([
            daysStep('mail', ['optional' => true, 'decline' => ['en' => 'Not now', 'pl' => 'Nie teraz', 'fr' => 'Pas maintenant', 'de' => 'Nicht jetzt', 'pt-BR' => 'Agora não'], 'reward' => ['cash_cents' => 50_000]]),
            daysStep('next'),
        ]);
        [$driver] = driverWithRun();

        daysGet($driver, ['X-Locale' => 'pl'])->assertJsonPath('data.current.key', 'mail')->assertJsonPath('data.current.optional', true)->assertJsonPath('data.current.decline', 'Nie teraz');

        $this->postJson('/api/v1/tutorial/decline', [], bearerFor($driver))->assertOk()
            ->assertJsonPath('data.current.key', 'next')
            ->assertJsonPath('data.current.optional', false)
            ->assertJsonPath('data.current.decline', null)
            ->assertJsonPath('data.done', 1)
            ->assertJsonPath('data.rewarded', []);

        expect(daysCashOf($driver))->toBe(0)
            ->and(TutorialProgress::query()->where('user_id', $driver->id)->where('step_key', 'mail')->value('state'))->toBe(TutorialProgress::STATE_DECLINED);

        daysReply($driver)->assertJsonPath('data.status', 'finished');
    });

    it('refuses a step that is not optional, and a driver with no step that waits', function () {
        theTutorialIs([daysStep('must')]);
        [$driver] = driverWithRun();

        $this->postJson('/api/v1/tutorial/decline', [], bearerFor($driver))->assertStatus(422)->assertJsonPath('code', 'tutorial_step_required');

        daysGet($driver)->assertJsonPath('data.current.key', 'must');
        $this->postJson('/api/v1/tutorial/decline', [], bearerFor($driver))->assertStatus(422)->assertJsonPath('code', 'tutorial_step_required');
    });

    it('is left out with the rest when the driver skips the tutorial, but a step that was declined stays declined', function () {
        theTutorialIs([daysStep('mail', ['optional' => true]), daysStep('next'), daysStep('last')]);
        [$driver] = driverWithRun();
        daysGet($driver);
        $this->postJson('/api/v1/tutorial/decline', [], bearerFor($driver))->assertOk();

        daysSkip($driver)->assertOk()->assertJsonPath('data.status', 'skipped');

        expect(TutorialProgress::query()->where('user_id', $driver->id)->pluck('state', 'step_key')->all())
            ->toEqual(['mail' => 'declined', 'next' => 'skipped', 'last' => 'skipped']);
    });
});

afterEach(function () {
    Carbon::setTestNow();
});
