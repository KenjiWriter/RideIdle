<?php

use App\Domain\Run\CarStats;
use App\Domain\Run\RunInputs;
use App\Domain\Run\TrackProfile;
use App\Models\RaceRun;
use App\Models\User;
use App\Services\Ledger;
use App\Services\RunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Concurrency tests commit real rows for other processes to see, so no wrapping transaction.
pest()->extend(TestCase::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function runService(): RunService
{
    return new RunService(new Ledger());
}

/** @param  array<string, mixed>  $attributes */
function aRun(array $attributes = []): RaceRun
{
    return RaceRun::factory()->create($attributes);
}

/**
 * A run whose driver already has some money to spend.
 *
 * @param  array<string, mixed>  $attributes
 */
function fundedRun(int $cents, array $attributes = []): RaceRun
{
    $run = aRun($attributes);

    if ($cents > 0) {
        (new Ledger())->post($run->user_id, $cents, 'gift', 'seed-money:'.$run->id);
    }

    return $run;
}

/**
 * The headers of a device token for a driver, to call the API as that driver.
 * The guards forget the user of an earlier request, which a test running several drivers in one process needs.
 *
 * @return array<string, string>
 */
function bearerFor(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

function cashOf(RaceRun $run): int
{
    return (new Ledger())->balance($run->user_id);
}

/** Dry weather only, so the lap time is the same everywhere and the arithmetic stays readable. */
function dryInputs(float $stat = 100.0, int $rewardCents = 2_000, int $breakChancePpm = 0): RunInputs
{
    return new RunInputs(
        car: new CarStats(['grip' => $stat, 'power' => $stat]),
        track: new TrackProfile(['grip' => 0.5, 'power' => 0.5], ['grip' => 100.0, 'power' => 100.0], 75_000, 1_500, $rewardCents, 30),
        weatherChancesPpm: ['dry' => 1_000_000],
        weatherEffects: [],
        climateSeed: 'region-zlomowo',
        breakChancePerLapPpm: $breakChancePpm,
    );
}

function lapMsOf(RaceRun $run): int
{
    return $run->config['lap_ms']['dry'];
}
