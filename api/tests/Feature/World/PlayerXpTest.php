<?php

declare(strict_types=1);

use App\Content\Rules;
use App\Models\Car;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

afterEach(fn () => Carbon::setTestNow());

/** A driver with two cars that both stand at T0 with a run each: the first is the one they play (the first they have), the second drives by itself. */
function driverWithTwoCars(): array
{
    [$driver, $run] = driverWithRun(['seed' => str_repeat('0', 32)]);

    $second = Car::factory()->create(['user_id' => $driver->id, 'vehicle_slug' => 'rdzak-gt-4']);
    $secondRun = RaceRun::factory()->create(['user_id' => $driver->id, 'car_id' => $second->id, 'car_slug' => 'rdzak-gt-4', 'track_slug' => 'zlomowo-street', 'cursor_ms' => RUN_T0, 'seed' => str_repeat('0', 32)]);

    return [$driver, $run, $secondRun];
}

function listCarsAt(User $driver, int $msAfterT0): TestResponse
{
    clockAt($msAfterT0);

    return test()->getJson('/api/v1/cars', bearerFor($driver));
}

describe('the experience of the driver', function () {
    it('comes from the laps of the car that is played, and the cars that are not played earn cash and level themselves', function () {
        [$driver, $played, $parked] = driverWithTwoCars();

        // Two laps of 75 s each, for both cars: $15.50 and 25 XP a lap.
        $response = listCarsAt($driver, 150_000)->assertOk()->assertJsonPath('wallet.xp', 50);

        expect($response->json('wallet.cash_cents'))->toBe(2 * 2 * 1_550)
            ->and(RaceRun::query()->find($played->id)->laps_done)->toBe(2)
            ->and(RaceRun::query()->find($parked->id)->laps_done)->toBe(2)
            // The car that stood in the garage was levelled by its laps all the same.
            ->and(Car::query()->find($parked->car_id)->xp)->toBe(Car::factory()->make()->xp + 2 * app(Rules::class)->carXpPerLap());
    });

    it('is written down in the settlement as what the driver got, and the settlement of a car that is not played says none', function () {
        [$driver, $played, $parked] = driverWithTwoCars();

        listCarsAt($driver, 150_000)->assertOk();

        expect(Settlement::query()->where('race_run_id', $played->id)->sum('xp'))->toBe(50)
            ->and(Settlement::query()->where('race_run_id', $parked->id)->sum('xp'))->toBe(0)
            ->and(Settlement::query()->where('race_run_id', $parked->id)->sum('laps'))->toBe(2);
    });

    it('goes to the car that is played from the moment it is selected, and the laps before are settled for the car that was played then', function () {
        [$driver, , $parked] = driverWithTwoCars();

        listCarsAt($driver, 150_000)->assertOk()->assertJsonPath('wallet.xp', 50);

        // Another two laps pass, and then the driver plays the second car: the laps until now belong to the car that was played until now.
        clockAt(300_000);
        test()->postJson("/api/v1/cars/{$parked->car_id}/select", [], bearerFor($driver))->assertOk()->assertJsonPath('wallet.xp', 100);

        // And from then on the first car is the one that earns cash only.
        listCarsAt($driver, 450_000)->assertOk()->assertJsonPath('wallet.xp', 150);
    });

    it('is all of the laps of a run that has no car, as it was before the cars were told apart', function () {
        $run = aRun(['car_id' => null, 'cursor_ms' => RUN_T0, 'seed' => str_repeat('0', 32)]);

        clockAt(150_000);
        runService()->settle($run->id);

        expect(Settlement::query()->where('race_run_id', $run->id)->sum('xp'))->toBe(50);
    });

    it('is what the rules say: none from the cars that are not played, and the game and the economy model say the same', function () {
        expect(app(Rules::class)->otherCarsXpPpm())->toBe(0)
            ->and(economyRules()->fleetModel()->otherCarsXpShare * 1_000_000)->toBe((float) app(Rules::class)->otherCarsXpPpm());
    });
});
