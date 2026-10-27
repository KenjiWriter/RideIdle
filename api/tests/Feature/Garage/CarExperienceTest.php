<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Models\Car;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\User;
use App\Services\AccountExport;
use Database\Factories\RaceRunFactory;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => withoutTireFee());
afterEach(fn () => Carbon::setTestNow());

/** The garage of the driver, which is where the car shows its level and its experience. */
function garageOf(User $driver): TestResponse
{
    return test()->getJson('/api/v1/garage', bearerFor($driver));
}

describe('the experience of a car', function () {
    it('starts at the experience at which its level begins', function () {
        [$driver, $run] = driverWithRun();

        expect($run->car->level)->toBe(3)
            ->and($run->car->xp)->toBe(600);

        garageOf($driver)->assertOk()
            ->assertJsonPath('data.car.level', 3)
            ->assertJsonPath('data.car.xp', ['total' => 600, 'level_start' => 600, 'next_level_at' => 1_200]);
    });

    it('gets 10 XP for every lap that is settled, and nothing for a lap that is not done yet', function () {
        [$driver, $run] = driverWithRun();

        clockAt(74_000);
        getRun($driver)->assertOk();
        expect($run->car->refresh()->xp)->toBe(600);

        // Three laps of 75 s are done at 230 s.
        clockAt(230_000);
        getRun($driver)->assertOk()->assertJsonPath('data.laps_done', 3);

        expect($run->car->refresh()->xp)->toBe(630);
    });

    it('does not count a lap twice when the run is looked at again', function () {
        [$driver, $run] = driverWithRun();
        clockAt(160_000);

        getRun($driver)->assertOk();
        getRun($driver)->assertOk();

        expect($run->car->refresh()->xp)->toBe(620);
    });

    it('gets the same experience for a lap in the rain, which is slower and pays less', function () {
        $config = [...RaceRunFactory::constantConfig(), 'lap_ms' => ['rain' => 84_000], 'lap_cash_cents' => ['rain' => 1_440], 'lap_xp' => ['rain' => 2], 'weather' => ['seed' => 'rainy', 'chances_ppm' => ['rain' => 1_000_000]]];
        [$driver, $run] = driverWithRun(['config' => $config]);
        clockAt(170_000);

        getRun($driver)->assertOk()->assertJsonPath('data.laps_done', 2);

        expect($run->car->refresh()->xp)->toBe(620);
    });

    it('rises a level when the experience reaches the next one, and shows what is left', function () {
        [$driver, $run] = driverWithRun();
        // Ten XP short of level 4.
        $run->car->update(['xp' => 1_190]);
        clockAt(80_000);

        getRun($driver)->assertOk()->assertJsonPath('data.laps_done', 1);

        garageOf($driver)->assertOk()
            ->assertJsonPath('data.car.level', 4)
            ->assertJsonPath('data.car.xp', ['total' => 1_200, 'level_start' => 1_200, 'next_level_at' => 2_000]);
    });

    it('can rise more than one level on a long absence', function () {
        [$driver, $run] = driverWithRun();
        // Two hours: 96 laps of 75 s, which is 960 XP, from 600 to 1 560.
        clockAt(7_200_000);

        getRun($driver)->assertOk();

        $car = $run->car->refresh();
        expect($car->xp)->toBeGreaterThan(600)
            ->and($car->level)->toBe(app(Rules::class)->carLevelAt($car->xp));
    });

    it('does not lower a level that the experience does not reach, as a car that was made a level higher is not set back', function () {
        $car = Car::factory()->create(['level' => 6, 'xp' => 0]);
        [$driver] = [$car->user];
        RaceRun::factory()->create(['user_id' => $driver->id, 'car_id' => $car->id, 'cursor_ms' => RUN_T0]);
        clockAt(160_000);

        getRun($driver)->assertOk();

        expect($car->refresh()->level)->toBe(6)
            ->and($car->xp)->toBe(20);
    });

    it('stops at the highest level and has no next one', function () {
        $car = Car::factory()->atLevel(30)->create();
        RaceRun::factory()->create(['user_id' => $car->user_id, 'car_id' => $car->id, 'cursor_ms' => RUN_T0]);
        clockAt(160_000);

        getRun($car->user)->assertOk();

        garageOf($car->user)->assertOk()
            ->assertJsonPath('data.car.level', 30)
            ->assertJsonPath('data.car.xp.next_level_at', null)
            ->assertJsonPath('data.car.tier_unlock', null);
    });

    it('says what the next level that opens a tier of parts opens', function () {
        [$driver, $run] = driverWithRun();

        // Level 3 mounts up to tier 2, and tier 3 asks for level 6.
        garageOf($driver)->assertOk()->assertJsonPath('data.car.tier_unlock', ['tier' => 3, 'level' => 6]);

        $run->car->update(['level' => 6, 'xp' => 3_000]);
        garageOf($driver)->assertOk()->assertJsonPath('data.car.tier_unlock', ['tier' => 4, 'level' => 9]);
    });

    it('tells what a lap gives the car, in the run', function () {
        [$driver] = driverWithRun();

        getRun($driver)->assertOk()->assertJsonPath('data.lap.car_xp', 10);
    });

    it('gives a car that starts a run the level and the experience of a new car', function () {
        $driver = User::factory()->guest()->create();
        $inputs = app(RunInputsFactory::class);

        $this->postJson('/api/v1/runs', ['car' => 'rdzak-gt-4', 'track' => 'zlomowo-street'], bearerFor($driver))->assertCreated();

        $car = Car::query()->where('user_id', $driver->id)->firstOrFail();
        expect($car->level)->toBe(3)
            ->and($car->xp)->toBe(600)
            ->and($inputs->tankMl('rdzak-gt-4'))->toBe(45_000);
    });

    it('is part of the copy of the data of the account', function () {
        [$driver] = driverWithRun();

        $export = (new AccountExport)->for($driver)['game'];

        expect($export['cars'][0])->toMatchArray(['level' => 3, 'xp' => 600]);
    });
});

describe('the level of a car and the parts it can have mounted', function () {
    it('lets a part that was too high for the car be mounted once the laps have raised it', function () {
        [$driver, $run] = driverWithRun();
        $part = Part::factory()->state(['part_slug' => 'rain-tires-t3', 'slot' => 'tires'])->create(['user_id' => $driver->id]);
        // Level 5, ten XP short of level 6, which is what a part of tier 3 asks for.
        $run->car->update(['level' => 5, 'xp' => 2_990]);
        $mount = fn () => test()->postJson('/api/v1/garage/jobs', ['kind' => 'mount', 'part' => $part->id], bearerFor($driver));

        $mount()->assertStatus(422)->assertJsonPath('code', 'car_level_required')->assertJsonPath('details', ['required' => 6, 'current' => 5]);

        clockAt(80_000);
        getRun($driver)->assertOk();

        $mount()->assertCreated();
    });
});
