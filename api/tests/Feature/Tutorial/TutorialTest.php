<?php

declare(strict_types=1);

use App\Content\TutorialSteps;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger;
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
            ->assertExactJson(['data' => ['status' => 'in_progress', 'total' => 6, 'done' => 0, 'current' => null, 'rewarded' => []]]);
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
            ->assertJsonPath('data.rewarded', [['key' => 'first-lap', 'cash_cents' => 20_000, 'car_xp' => 0]]);

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
            ->assertJsonPath('data.rewarded', [['key' => 'rewards', 'cash_cents' => 0, 'car_xp' => 100]]);
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

    it('pays nothing in experience to a driver who has no car, and still ends the step', function () {
        $driver = User::factory()->guest()->create();
        tutorialBefore($driver, 'rewards');
        TutorialProgress::factory()->create(['user_id' => $driver->id, 'step_key' => 'rewards']);

        // The step before it needs a run to have been done, which it was by the setup above: here only the answer counts.
        replyToTutorial($driver)->assertOk()->assertJsonPath('data.rewarded', []);
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
            ->assertJsonPath('data.rewarded', [['key' => 'refuel', 'cash_cents' => 9_000, 'car_xp' => 0]])
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

        getTutorial($driver)->assertOk()
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.done', 6)
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('data.rewarded', [['key' => 'repair', 'cash_cents' => 3_000, 'car_xp' => 0]]);

        // The repair cost $30 and the step gave it back.
        expect(driverCash($driver))->toBe(10_000);
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
            ->and(TutorialProgress::query()->where('user_id', $driver->id)->where('state', 'skipped')->count())->toBe(5);
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
