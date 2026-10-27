<?php

declare(strict_types=1);

use App\Content\TutorialSteps;
use App\Models\CityGarage;
use App\Models\CrateOpening;
use App\Models\LedgerEntry;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\Travel;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WorkshopJob;
use App\Services\Ledger;
use App\Services\TutorialScript;
use Illuminate\Testing\TestResponse;

/** @param  array<string, string>  $headers */
function getTutorial(User $driver, array $headers = []): TestResponse
{
    return test()->getJson('/api/v1/tutorial', [...bearerFor($driver), ...$headers]);
}

function replyToTutorial(User $driver): TestResponse
{
    return test()->postJson('/api/v1/tutorial/reply', [], bearerFor($driver));
}

function skipTutorial(User $driver): TestResponse
{
    return test()->postJson('/api/v1/tutorial/skip', [], bearerFor($driver));
}

/** The steps before this one are done, so that a test starts in the middle of the tutorial. */
function tutorialBefore(User $driver, string $key): void
{
    foreach (array_column(app(TutorialSteps::class)->all(), 'key') as $earlier) {
        if ($earlier === $key) {
            return;
        }

        TutorialProgress::factory()->create(['user_id' => $driver->id, 'step_key' => $earlier, 'state' => TutorialProgress::STATE_DONE, 'completed_at' => now()]);
    }
}

function driverCash(User $driver): int
{
    return (int) Wallet::query()->find($driver->id)?->cash_cents;
}

function giveCash(User $driver, int $cents): void
{
    (new Ledger)->post($driver->id, $cents, 'gift', 'seed-money:'.$driver->id);
}

describe('the tutorial', function () {
    it('requires a token', function () {
        $this->getJson('/api/v1/tutorial')->assertUnauthorized();
        $this->postJson('/api/v1/tutorial/reply')->assertUnauthorized();
        $this->postJson('/api/v1/tutorial/skip')->assertUnauthorized();
    });

    it('shows nothing to a driver who has not chosen a wreck, and is never cached', function () {
        $driver = User::factory()->guest()->create();

        getTutorial($driver)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => ['status' => 'in_progress', 'total' => 10, 'done' => 0, 'current' => null, 'rewarded' => []]]);
    });

    it('asks the driver to send the car out when the wreck is chosen, with the messages in the language of the driver', function () {
        [$driver] = driverWithRun(['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => RaceRun::STOP_PARKED]);

        $response = getTutorial($driver, ['X-Locale' => 'pl'])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.done', 1)
            ->assertJsonPath('data.current.key', 'start')
            ->assertJsonPath('data.current.number', 2)
            ->assertJsonPath('data.current.target', 'start')
            ->assertJsonPath('data.current.acknowledged', false)
            ->assertJsonPath('data.current.reply', 'OK, ruszam')
            ->assertJsonPath('data.rewarded', []);

        expect($response->json('data.current.sender'))->toBe('+48 555 010 120')
            ->and($response->json('data.current.sms'))->toHaveCount(2)
            ->and($response->json('data.current.sms.1'))->toStartWith('Wejdź w „Turniej”');
    });

    it('waits for the Start while the car stands in the garage, and goes on to the first lap when it is sent out', function () {
        [$driver, $run] = driverWithRun(['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => RaceRun::STOP_PARKED]);
        getTutorial($driver);

        replyToTutorial($driver)->assertOk()->assertJsonPath('data.current.key', 'start')->assertJsonPath('data.current.acknowledged', true);

        // The driver answered, but the car still stands: the step waits for the thing it asked, the Start.
        getTutorial($driver)->assertJsonPath('data.current.key', 'start')->assertJsonPath('data.done', 1);

        $this->postJson("/api/v1/runs/{$run->id}/start", [], bearerFor($driver))->assertOk();

        getTutorial($driver)->assertOk()
            ->assertJsonPath('data.done', 2)
            ->assertJsonPath('data.current.key', 'first-lap')
            ->assertJsonPath('data.current.number', 3);
    });

    it('is ready for the first lap when the car is out, with the messages in the language of the driver', function () {
        [$driver] = driverWithRun();
        tutorialBefore($driver, 'first-lap');

        $response = getTutorial($driver, ['X-Locale' => 'pl'])->assertOk()
            ->assertJsonPath('data.done', 2)
            ->assertJsonPath('data.current.key', 'first-lap')
            ->assertJsonPath('data.current.number', 3)
            ->assertJsonPath('data.current.target', 'laps')
            ->assertJsonPath('data.current.acknowledged', false)
            ->assertJsonPath('data.current.reply', 'OK, czekam')
            ->assertJsonPath('data.rewarded', []);

        expect($response->json('data.current.sender'))->toBe('+48 555 010 134')
            ->and($response->json('data.current.sms'))->toHaveCount(2)
            ->and($response->json('data.current.sms.1'))->toStartWith('Jedzie!');
    });

    it('speaks English to a driver who speaks it', function () {
        [$driver] = driverWithRun();

        getTutorial($driver, ['X-Locale' => 'en'])->assertJsonPath('data.current.reply', 'OK, on my way');
    });

    it('pays the first reward when the first lap is done, once, and shows the next step', function () {
        [$driver, $run] = driverWithRun();
        tutorialBefore($driver, 'first-lap');
        getTutorial($driver)->assertJsonPath('data.current.key', 'first-lap');

        // Eighty seconds later the car has finished its first lap, which pays $15.50.
        clockAt(80_000);

        getTutorial($driver)->assertOk()
            ->assertJsonPath('data.done', 3)
            ->assertJsonPath('data.current.key', 'rewards')
            ->assertJsonPath('data.current.target', 'cash')
            ->assertJsonPath('data.rewarded', [['key' => 'first-lap', 'cash_cents' => 20_000, 'car_xp' => 0, 'xp' => 40, 'part' => null, 'garage' => null]]);

        expect(driverCash($driver))->toBe(1_550 + 20_000)
            ->and($run->refresh()->laps_done)->toBe(1);

        // Looking again pays nothing more.
        getTutorial($driver)->assertJsonPath('data.rewarded', []);
        expect(driverCash($driver))->toBe(1_550 + 20_000)
            ->and(LedgerEntry::query()->where('user_id', $driver->id)->where('reason', 'tutorial')->count())->toBe(1);
    });

    it('does not complete a step in the same look that showed it', function () {
        // The car has been driving for an hour, so the first lap is long done when the driver is first asked.
        [$driver] = driverWithRun();
        tutorialBefore($driver, 'first-lap');
        clockAt(3_600_000);

        getTutorial($driver)->assertJsonPath('data.current.key', 'first-lap')->assertJsonPath('data.rewarded', []);
        getTutorial($driver)->assertJsonPath('data.current.key', 'rewards')->assertJsonPath('data.rewarded.0.key', 'first-lap');
    });

    it('puts the phone away when the driver answers, and the step goes on waiting', function () {
        [$driver] = driverWithRun();
        tutorialBefore($driver, 'first-lap');
        getTutorial($driver);

        replyToTutorial($driver)->assertOk()
            ->assertJsonPath('data.current.key', 'first-lap')
            ->assertJsonPath('data.current.acknowledged', true);

        // Answering twice answers once.
        replyToTutorial($driver)->assertJsonPath('data.current.acknowledged', true);
        expect(TutorialProgress::query()->where('user_id', $driver->id)->where('step_key', 'first-lap')->count())->toBe(1);
    });

    it('finishes the step that ends with the answer, and waits for the tank to run low', function () {
        [$driver] = driverWithRun();
        tutorialBefore($driver, 'rewards');
        TutorialProgress::factory()->create(['user_id' => $driver->id, 'step_key' => 'rewards']);

        replyToTutorial($driver)->assertOk()
            ->assertJsonPath('data.done', 4)
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('data.rewarded', [['key' => 'rewards', 'cash_cents' => 0, 'car_xp' => 100, 'xp' => 100, 'part' => null, 'garage' => null]]);
    });

    it('gives the experience of the step to the car of the driver, and it can level the car', function () {
        [$driver, $run] = driverWithRun();
        tutorialBefore($driver, 'rewards');
        TutorialProgress::factory()->create(['user_id' => $driver->id, 'step_key' => 'rewards']);
        // A hundred XP short of level 4, so that the 100 of the step is what makes it.
        $run->car->update(['xp' => 1_100]);

        replyToTutorial($driver)->assertOk();

        $car = $run->car->refresh();
        expect($car->xp)->toBe(1_200)
            ->and($car->level)->toBe(4);
    });

    it('does not give the experience twice for a step that is done', function () {
        [$driver, $run] = driverWithRun();
        tutorialBefore($driver, 'rewards');
        TutorialProgress::factory()->create(['user_id' => $driver->id, 'step_key' => 'rewards']);

        replyToTutorial($driver)->assertOk();
        replyToTutorial($driver)->assertOk()->assertJsonPath('data.rewarded', []);
        getTutorial($driver)->assertOk()->assertJsonPath('data.rewarded', []);

        expect($run->car->refresh()->xp)->toBe(700);
    });

    it('pays nothing in experience of the car to a driver who has no car, and still ends the step and gives the experience of the driver', function () {
        $driver = User::factory()->guest()->create();
        tutorialBefore($driver, 'rewards');
        TutorialProgress::factory()->create(['user_id' => $driver->id, 'step_key' => 'rewards']);

        // The step before it needs a run to have been done, which it was by the setup above: here only the answer counts.
        replyToTutorial($driver)->assertOk()->assertJsonPath('data.rewarded', [['key' => 'rewards', 'cash_cents' => 0, 'car_xp' => 0, 'xp' => 100, 'part' => null, 'garage' => null]]);
    });

    it('sends a message about the tank when it is under a quarter full, and pays the tank when it is filled again', function () {
        [$driver, $run] = driverWithRun(['fuel_ml' => 5_000]);
        tutorialBefore($driver, 'refuel');
        giveCash($driver, 20_000);

        getTutorial($driver, ['X-Locale' => 'en'])
            ->assertJsonPath('data.current.key', 'refuel')
            ->assertJsonPath('data.current.target', 'refuel')
            ->assertJsonPath('data.current.number', 5);

        // 40 litres at $2 a litre, then the tank is full.
        $this->postJson("/api/v1/runs/{$run->id}/refuel", ['request_id' => 'r1'], bearerFor($driver))->assertOk();

        getTutorial($driver)->assertOk()
            ->assertJsonPath('data.done', 5)
            ->assertJsonPath('data.rewarded', [['key' => 'refuel', 'cash_cents' => 9_000, 'car_xp' => 0, 'xp' => 180, 'part' => null, 'garage' => null]])
            ->assertJsonPath('data.current', null);
    });

    it('does not take a tank that is half empty for a low one', function () {
        [$driver] = driverWithRun(['fuel_ml' => 22_000]);
        tutorialBefore($driver, 'refuel');

        getTutorial($driver)->assertJsonPath('data.current', null)->assertJsonPath('data.done', 4);
    });

    it('does not call a tank filled before it is at four fifths', function () {
        [$driver, $run] = driverWithRun(['fuel_ml' => 5_000]);
        tutorialBefore($driver, 'refuel');
        giveCash($driver, 20_000);
        getTutorial($driver)->assertJsonPath('data.current.key', 'refuel');

        // 20 litres more is 25 of 45: not there yet.
        $this->postJson("/api/v1/runs/{$run->id}/refuel", ['request_id' => 'r1', 'liters' => 20], bearerFor($driver))->assertOk();

        getTutorial($driver)->assertJsonPath('data.current.key', 'refuel')->assertJsonPath('data.rewarded', []);
    });

    it('sends a message about a breakdown, and pays the repair when the car drives again, and then it is done', function () {
        [$driver, $run] = driverWithRun(['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => 'breakdown']);
        tutorialBefore($driver, 'repair');
        giveCash($driver, 10_000);

        getTutorial($driver)->assertJsonPath('data.current.key', 'repair')->assertJsonPath('data.current.target', 'repair');

        $this->postJson("/api/v1/runs/{$run->id}/repair", ['request_id' => 'p1'], bearerFor($driver))->assertOk();

        // The step goes on to the pair of tires that the repair gives away, which the next step asks to be fitted.
        getTutorial($driver)->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.done', 6)
            ->assertJsonPath('data.current.key', 'mount')
            ->assertJsonPath('data.current.target', 'mount')
            ->assertJsonPath('data.rewarded', [['key' => 'repair', 'cash_cents' => 3_000, 'car_xp' => 0, 'xp' => 0, 'part' => 'street-tires-t2', 'garage' => null]]);

        // The repair cost $30 and the step gave it back, and the tires are in the inventory, common.
        expect(driverCash($driver))->toBe(10_000)
            ->and(Part::query()->where('user_id', $driver->id)->where('part_slug', 'street-tires-t2')->where('rarity', 1)->count())->toBe(1);
    });

    it('does not start with a breakdown that came before the tank was dealt with', function () {
        [$driver] = driverWithRun(['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => 'breakdown']);
        tutorialBefore($driver, 'refuel');

        // The step about the tank comes first, so the step about the repair waits for it.
        getTutorial($driver)->assertJsonPath('data.current', null)->assertJsonPath('data.done', 4);
    });

    it('leaves out the steps that are not done when the driver skips, and pays nothing for them', function () {
        [$driver] = driverWithRun();
        getTutorial($driver);

        skipTutorial($driver)->assertOk()
            ->assertJsonPath('data.status', 'skipped')
            ->assertJsonPath('data.done', 1)
            ->assertJsonPath('data.current', null);

        // The first lap is done now, but the step was left out: there is no reward and no message.
        clockAt(80_000);
        getTutorial($driver)->assertJsonPath('data.status', 'skipped')->assertJsonPath('data.current', null)->assertJsonPath('data.rewarded', []);

        expect(driverCash($driver))->toBe(1_550)
            ->and(TutorialProgress::query()->where('user_id', $driver->id)->where('state', 'skipped')->count())->toBe(9);
    });

    it('keeps what was done when the driver skips, and what it was paid', function () {
        [$driver] = driverWithRun();
        tutorialBefore($driver, 'first-lap');
        getTutorial($driver);
        clockAt(80_000);
        getTutorial($driver)->assertJsonPath('data.current.key', 'rewards');

        skipTutorial($driver)->assertJsonPath('data.done', 3)->assertJsonPath('data.status', 'skipped');
        expect(driverCash($driver))->toBe(1_550 + 20_000);
    });

    it('keeps the progress of one driver from another', function () {
        [$one] = driverWithRun(['status' => RaceRun::STATUS_STOPPED, 'stop_reason' => RaceRun::STOP_PARKED]);
        $other = User::factory()->guest()->create();
        getTutorial($one);

        getTutorial($other)->assertJsonPath('data.done', 0)->assertJsonPath('data.current', null);
        skipTutorial($other);

        getTutorial($one)->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.current.key', 'start');
    });
});

describe('the guided first minutes', function () {
    it('has ten steps that add up to the experience of the eighth level less the laps, and the cash of the design', function () {
        $steps = app(TutorialSteps::class)->all();
        $xp = array_sum(array_map(fn (array $step): int => $step['reward']['xp'] ?? 0, $steps));
        $cash = array_sum(array_map(fn (array $step): int => $step['reward']['cash_cents'] ?? 0, array_filter($steps, fn (array $step): bool => $step['key'] !== 'repair')));

        // 4 800 XP make the eighth level; the six laps before the breakdown give about 120 to 140 of them (measured: 138 for seven laps), so the steps give 4 700
        // and the driver is at the eighth level when the journey starts. The repair gives back what it cost, so it is no gift.
        expect(array_column($steps, 'key'))->toBe(['wreck', 'start', 'first-lap', 'rewards', 'refuel', 'repair', 'mount', 'crate', 'locked', 'travel'])
            ->and($xp)->toBe(4_700)
            ->and($cash)->toBe(59_000);
    });

    it('asks to fit the tires, and pays the experience of the step when a job is in the queue of the workshop', function () {
        [$driver, $run] = driverWithRun();
        tutorialBefore($driver, 'mount');
        getTutorial($driver)->assertJsonPath('data.current.key', 'mount');

        WorkshopJob::factory()->create(['user_id' => $driver->id, 'car_id' => $run->car_id]);

        getTutorial($driver)->assertOk()
            ->assertJsonPath('data.done', 7)
            ->assertJsonPath('data.current.key', 'crate')
            ->assertJsonPath('data.current.target', 'crate')
            ->assertJsonPath('data.rewarded', [['key' => 'mount', 'cash_cents' => 0, 'car_xp' => 0, 'xp' => 360, 'part' => null, 'garage' => null]]);

        expect(Wallet::query()->find($driver->id)->xp)->toBe(360);
    });

    it('makes the first mounting short while the step is on, so that the journey does not wait for the mechanic, and the next jobs last what the rules say', function () {
        [$driver] = driverWithRun();
        $tires = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'street-tires-t2', 'slot' => 'tires']);
        $exhaust = Part::factory()->create(['user_id' => $driver->id, 'part_slug' => 'sport-exhaust-t2', 'slot' => 'exhaust']);

        // The repair is not done yet: nothing is arranged, and the job lasts 120 000 x 2 x 0.98.
        expect(app(TutorialScript::class)->firstMountMs($driver->id))->toBeNull();

        tutorialBefore($driver, 'mount');
        expect(app(TutorialScript::class)->firstMountMs($driver->id))->toBe(30_000);

        // The garage says the same before the job is queued: short, and with no fee for the tires.
        $this->getJson('/api/v1/garage', bearerFor($driver))->assertOk()
            ->assertJsonPath('data.inventory.0.mount_next', ['cost_cents' => 0, 'duration_ms' => 30_000]);

        $first = $this->postJson('/api/v1/garage/jobs', ['kind' => 'mount', 'part' => $tires->id], bearerFor($driver))->assertCreated()->json('data.workshop.jobs.0');

        // The first job is short, and it is not queued again behind a longer one.
        // Nor does the vulcanizer outside ask its fee of it: the driver has no cash, and the job costs nothing.
        expect($first['finish_ms'] - $first['start_ms'])->toBe(30_000)
            ->and($first['cost_cents'])->toBe(0)
            ->and(driverCash($driver))->toBe(0)
            ->and(app(TutorialScript::class)->firstMountMs($driver->id))->toBeNull();

        $second = $this->postJson('/api/v1/garage/jobs', ['kind' => 'mount', 'part' => $exhaust->id], bearerFor($driver))->assertCreated()->json('data.workshop.jobs.1');

        expect($second['finish_ms'] - $second['start_ms'])->toBe(235_200);
    });

    it('makes the first crate free, once, with no posting, and the next one costs what it costs', function () {
        [$driver] = driverWithRun();
        tutorialBefore($driver, 'crate');
        giveCash($driver, 100_000);

        $this->getJson('/api/v1/shop', bearerFor($driver))->assertOk()
            ->assertJsonPath('data.crates.free_first', true)
            ->assertJsonPath('data.crates.kinds.0.slots.0.price_cents', 0);

        $first = $this->postJson('/api/v1/shop/crates/slot/open', ['slot' => 'tires', 'request_id' => 'c1'], bearerFor($driver))->assertCreated();

        expect($first->json('opened.cost_cents'))->toBe(0)
            ->and(driverCash($driver))->toBe(100_000)
            ->and(CrateOpening::query()->where('user_id', $driver->id)->value('request_id'))->toBe('c1');

        // The same request again opens nothing, and the shop says that the first crate is gone.
        $this->postJson('/api/v1/shop/crates/slot/open', ['slot' => 'tires', 'request_id' => 'c1'], bearerFor($driver))->assertOk()->assertJsonPath('repeated', true);
        $this->getJson('/api/v1/shop', bearerFor($driver))->assertJsonPath('data.crates.free_first', false);

        $second = $this->postJson('/api/v1/shop/crates/slot/open', ['slot' => 'tires', 'request_id' => 'c2'], bearerFor($driver))->assertCreated();

        expect($second->json('opened.cost_cents'))->toBeGreaterThan(0)
            ->and(driverCash($driver))->toBeLessThan(100_000)
            ->and(CrateOpening::query()->where('user_id', $driver->id)->count())->toBe(2);
    });

    it('does not make a crate free before the tires are fitted, or for a driver who skipped, or after the step of the crate is done', function () {
        [$driver] = driverWithRun();
        tutorialBefore($driver, 'mount');
        expect(app(TutorialScript::class)->freeFirstCrate($driver->id))->toBeFalse();

        TutorialProgress::factory()->create(['user_id' => $driver->id, 'step_key' => 'mount', 'state' => TutorialProgress::STATE_DONE, 'completed_at' => now()]);
        expect(app(TutorialScript::class)->freeFirstCrate($driver->id))->toBeTrue();

        skipTutorial($driver);
        expect(app(TutorialScript::class)->freeFirstCrate($driver->id))->toBeFalse();
    });

    it('is done for the crate when a crate is opened, and the step of the lock is a message that is answered: it pays the experience and gives the garage of Kruszyn', function () {
        [$driver] = driverWithRun();
        tutorialBefore($driver, 'crate');
        CrateOpening::query()->create(['user_id' => $driver->id, 'kind' => 'slot', 'pity_group' => 'standard', 'opening_index' => 0, 'slot' => 'tires', 'tier' => 1, 'rarity' => 1, 'part_slug' => 'street-tires-t1', 'price_cents' => 0]);

        // The step of the crate is shown first, and done on the next look: the crate is opened by then.
        getTutorial($driver)->assertOk()->assertJsonPath('data.current.key', 'crate');

        getTutorial($driver)->assertOk()
            ->assertJsonPath('data.done', 8)
            ->assertJsonPath('data.current.key', 'locked')
            ->assertJsonPath('data.current.target', null);

        replyToTutorial($driver)->assertOk()
            ->assertJsonPath('data.done', 9)
            ->assertJsonPath('data.current.key', 'travel')
            ->assertJsonPath('data.rewarded', [['key' => 'locked', 'cash_cents' => 0, 'car_xp' => 0, 'xp' => 1_160, 'part' => null, 'garage' => 'kruszyn']]);

        expect(CityGarage::query()->where('user_id', $driver->id)->where('region', 'kruszyn')->count())->toBe(1);

        // Looking again gives nothing more.
        getTutorial($driver)->assertJsonPath('data.rewarded', []);
        expect(CityGarage::query()->where('user_id', $driver->id)->where('region', 'kruszyn')->count())->toBe(1)
            // 760 for the crate and 1 160 for the lock.
            ->and(Wallet::query()->find($driver->id)->xp)->toBe(760 + 1_160);
    });

    it('finishes with the journey: the experience that makes the eighth level, and the cash of the last step', function () {
        [$driver, $run] = driverWithRun();
        tutorialBefore($driver, 'travel');
        getTutorial($driver)->assertJsonPath('data.current.key', 'travel')->assertJsonPath('data.current.target', 'travel');

        Travel::factory()->create(['user_id' => $driver->id, 'race_run_id' => $run->id]);

        getTutorial($driver)->assertOk()
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.done', 10)
            ->assertJsonPath('data.rewarded', [['key' => 'travel', 'cash_cents' => 30_000, 'car_xp' => 0, 'xp' => 2_100, 'part' => null, 'garage' => null]]);

        expect(driverCash($driver))->toBe(30_000);
    });

    it('starts the first run of a driver with a tank that is low in a few minutes, and no other run', function () {
        $driver = User::factory()->guest()->create();

        $first = $this->postJson('/api/v1/runs', ['car' => 'rdzak-gt-4', 'track' => 'zlomowo-street'], bearerFor($driver))->assertCreated();

        // 17 l of 45 l: under a quarter (11.25 l) after four laps of 1.5 l, which is where the message about the tank comes.
        expect($first->json('data.fuel_ml'))->toBe(17_000)
            ->and($first->json('data.tank_ml'))->toBe(45_000)
            ->and(app(TutorialScript::class)->firstRunFuelMl($driver->id))->toBeNull();
    });

    it('plans the breakdown of the first car after six laps, until the repair is done, and for no other car', function () {
        $driver = User::factory()->guest()->create();
        $script = app(TutorialScript::class);

        // Before a run exists the run that is about to be made is the first.
        expect($script->breakdownLap($driver->id, null, 0))->toBe(6);

        $run = RaceRun::factory()->create(['user_id' => $driver->id]);
        $other = RaceRun::factory()->create(['user_id' => $driver->id]);

        expect($script->breakdownLap($driver->id, $run->id, 0))->toBe(6)
            ->and($script->breakdownLap($driver->id, $run->id, 5))->toBe(6)
            // The lap is behind it, or the run is not the first, or a run is about to be made when one exists.
            ->and($script->breakdownLap($driver->id, $run->id, 6))->toBeNull()
            ->and($script->breakdownLap($driver->id, $other->id, 0))->toBeNull()
            ->and($script->breakdownLap($driver->id, null, 0))->toBeNull();

        TutorialProgress::factory()->create(['user_id' => $driver->id, 'step_key' => 'repair', 'state' => TutorialProgress::STATE_DONE, 'completed_at' => now()]);

        expect($script->breakdownLap($driver->id, $run->id, 0))->toBeNull();
    });

    it('freezes the first run with the breakdown at lap six', function () {
        $driver = User::factory()->guest()->create();

        $this->postJson('/api/v1/runs', ['car' => 'rdzak-gt-4', 'track' => 'zlomowo-street'], bearerFor($driver))->assertCreated();

        expect(RaceRun::query()->where('user_id', $driver->id)->value('break_after_laps'))->toBe(6);
    });
});
