<?php

declare(strict_types=1);

use App\Content\MissionCatalog;
use App\Content\Rules;
use App\Models\RaceRun;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WorkshopJob;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

// What the tests of the missions share: the pools they run on, a driver who is past the tutorial, and the calls of the API.

/** A moment that is in the middle of a day of the missions, in the time zone of the game (Warsaw, the day starts at four in the morning). */
function missionNoon(): Carbon
{
    return Carbon::parse('2026-10-30 12:00:00', 'Europe/Warsaw');
}

/** @return array<string, mixed> */
function missionTemplate(string $objective, array $extra = []): array
{
    return ['objective' => $objective, 'weight' => 1, 'reward_share' => 1, ...$extra];
}

/**
 * The pools of the tests: a set of a day of two missions (a few laps, one service), a week and a month of one. Every slot has one template, so the draw has no choice.
 *
 * @param  array<string, mixed>  $daily  more or other slots of the day: the key of the slot and its templates
 */
function theMissionsAre(?array $daily = null, ?array $weekly = null, ?array $monthly = null): void
{
    app()->instance(MissionCatalog::class, MissionCatalog::fromArray([
        'schema' => 0,
        'version' => '2026.10.1',
        'periodic' => [
            'daily' => $daily ?? [
                ['key' => 'drive', 'templates' => ['t-laps' => missionTemplate('laps', ['tanks_ppm' => 200_000])]],
                ['key' => 'workshop', 'templates' => ['t-service' => missionTemplate('service', ['count' => 1, 'items' => [['type' => 'crate', 'kind' => 'slot']]])]],
            ],
            'weekly' => $weekly ?? [['key' => 'drive', 'templates' => ['t-week-laps' => missionTemplate('laps', ['tanks_ppm' => 500_000])]]],
            'monthly' => $monthly ?? [['key' => 'drive', 'templates' => ['t-month-laps' => missionTemplate('laps', ['tanks_ppm' => 900_000])]]],
        ],
        'chains' => [],
    ]));
}

/** The rules of the repository with a change made, for the tests of a game that pays less, or nothing. @param  callable(array<string, mixed>): array<string, mixed>  $change */
function missionRules(callable $change): void
{
    $data = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR);

    app()->singleton(Rules::class, fn () => Rules::fromArray($change($data)));
}

/** A driver who is past the tutorial, with a car that has a run that was started at the moment the clock stands at. */
function missionDriver(bool $started = true): User
{
    Carbon::setTestNow(missionNoon());

    $driver = User::factory()->guest()->create();
    TutorialProgress::query()->create(['user_id' => $driver->id, 'step_key' => 'travel', 'state' => TutorialProgress::STATE_DONE]);

    if ($started) {
        RaceRun::factory()->create(['user_id' => $driver->id, 'cursor_ms' => Carbon::now()->getTimestampMs()]);
    }

    return $driver;
}

function missionsOf(User $driver): TestResponse
{
    return test()->getJson('/api/v1/missions', bearerFor($driver));
}

function claimMission(User $driver, int $id): TestResponse
{
    return test()->postJson("/api/v1/missions/{$id}/claim", [], bearerFor($driver));
}

function claimBonus(User $driver, string $kind = 'daily'): TestResponse
{
    return test()->postJson("/api/v1/missions/{$kind}/bonus", [], bearerFor($driver));
}

function cashOfDriver(User $driver): int
{
    return (int) Wallet::query()->find($driver->id)?->cash_cents;
}

function coinsOfDriver(User $driver): int
{
    return (int) Wallet::query()->find($driver->id)?->premium_coins;
}

/** A job of the workshop that the mechanic finished at the moment the clock stands at. */
function doneJob(User $driver, string $kind = WorkshopJob::KIND_SERVICE, array $extra = []): WorkshopJob
{
    $car = RaceRun::query()->where('user_id', $driver->id)->firstOrFail()->car_id;
    $now = Carbon::now()->getTimestampMs();

    return WorkshopJob::factory()->create([...['user_id' => $driver->id, 'car_id' => $car, 'kind' => $kind, 'status' => WorkshopJob::STATUS_DONE, 'start_ms' => $now - 60_000, 'finish_ms' => $now - 1_000], ...$extra]);
}
