<?php

declare(strict_types=1);

use App\Models\Part;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\Travel;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Services\AwaySummary;
use Illuminate\Support\Carbon;

// What happened to the whole account while the driver was away: the break is noted when the driver comes back after a long pause, and the summary of it (what each
// car earned, the jobs that were done, the journeys that ended, the cars that wait) stays until the driver has seen it.

afterEach(fn () => Carbon::setTestNow());

const AWAY_NOW = '2026-10-28 12:00:00';

function awayMs(string $moment): int
{
    return Carbon::parse($moment)->getTimestampMs();
}

/** A driver who was away from 06:00 to 11:00 (they are here now, at 12:00, and were here at 11:50). */
function awayDriver(array $attributes = []): User
{
    Carbon::setTestNow(AWAY_NOW);

    return User::factory()->guest()->create([
        'last_seen_at' => '2026-10-28 11:50:00',
        'previous_seen_at' => '2026-10-28 06:00:00',
        'away_from' => '2026-10-28 06:00:00',
        'away_to' => '2026-10-28 11:00:00',
        ...$attributes,
    ]);
}

/** A car that is not driving: it has a run that is stopped (so that nothing is settled for it) and settlements that were made. */
function awayCar(User $driver, string $reason = 'parked', string $vehicle = 'rdzak-gt-4'): RaceRun
{
    return RaceRun::factory()->create(['user_id' => $driver->id, 'car_slug' => $vehicle, 'status' => RaceRun::STATUS_STOPPED, 'stop_reason' => $reason, 'cursor_ms' => awayMs(AWAY_NOW)]);
}

function awaySettled(RaceRun $run, string $from, string $to, int $cash, int $laps): void
{
    Settlement::query()->create([
        'race_run_id' => $run->id,
        'state_version' => Settlement::query()->where('race_run_id', $run->id)->count() + 1,
        'cursor_from_ms' => awayMs($from),
        'cursor_to_ms' => awayMs($to),
        'now_ms' => awayMs($to),
        'laps' => $laps,
        'cash_cents' => $cash,
        'xp' => 0,
        'created_at' => $to,
    ]);
}

function awayShow(User $driver, array $headers = [])
{
    return test()->getJson('/api/v1/away', [...bearerFor($driver), ...$headers]);
}

describe('noting the break', function () {
    it('notes it when the driver comes back after a long pause, from the visit before to this one', function () {
        Carbon::setTestNow(AWAY_NOW);
        $driver = User::factory()->guest()->create(['last_seen_at' => '2026-10-28 07:00:00']);

        awayShow($driver)->assertOk()->assertJsonPath('data.pending', true)->assertJsonPath('data.away_minutes', 300);

        $driver->refresh();

        expect($driver->away_from->toDateTimeString())->toBe('2026-10-28 07:00:00')
            ->and($driver->away_to->toDateTimeString())->toBe('2026-10-28 12:00:00');
    });

    it('does not note a pause that is too short', function () {
        Carbon::setTestNow(AWAY_NOW);
        $driver = User::factory()->guest()->create(['last_seen_at' => '2026-10-28 11:46:00']);

        awayShow($driver)->assertOk()->assertJsonPath('data.pending', false);

        expect($driver->refresh()->away_from)->toBeNull();
    });

    it('joins a second break to the first one the driver did not look at', function () {
        Carbon::setTestNow(AWAY_NOW);
        $driver = User::factory()->guest()->create(['last_seen_at' => '2026-10-28 10:00:00', 'away_from' => '2026-10-27 22:00:00', 'away_to' => '2026-10-28 08:00:00']);

        awayShow($driver)->assertOk()->assertJsonPath('data.pending', true);

        $driver->refresh();

        expect($driver->away_from->toDateTimeString())->toBe('2026-10-27 22:00:00')
            ->and($driver->away_to->toDateTimeString())->toBe('2026-10-28 12:00:00');
    });

    it('is the summary of nothing for a driver who has no break to tell of, and is for a signed-in driver only', function () {
        $this->getJson('/api/v1/away')->assertUnauthorized();

        Carbon::setTestNow(AWAY_NOW);
        $driver = User::factory()->guest()->create(['last_seen_at' => '2026-10-28 11:55:00']);

        awayShow($driver)->assertOk()->assertJsonPath('data', ['pending' => false]);
    });

    it('is over when the driver says they have seen it, and the next break is a new one', function () {
        $driver = awayDriver();

        awayShow($driver)->assertOk()->assertJsonPath('data.pending', true);
        test()->postJson('/api/v1/away/seen', [], bearerFor($driver))->assertOk()->assertJsonPath('data.pending', false);

        expect($driver->refresh()->away_from)->toBeNull()->and($driver->away_to)->toBeNull();

        awayShow($driver)->assertOk()->assertJsonPath('data.pending', false);
    });
});

describe('the cars', function () {
    it('say what each earned in the break, the one that earned most first, and share a settlement that is half in the break', function () {
        $driver = awayDriver();
        $played = awayCar($driver);
        $driver->forceFill(['selected_car_id' => $played->car_id])->save();
        $other = awayCar($driver, vehicle: 'bizon-m-2');
        awaySettled($played, '2026-10-28 06:30:00', '2026-10-28 10:30:00', 1_000, 20);
        // From 05:00 to 07:00: an hour of it is in the break (06:00 to 11:00).
        awaySettled($other, '2026-10-28 05:00:00', '2026-10-28 07:00:00', 3_000, 10);
        // Before the break: not told.
        awaySettled($other, '2026-10-28 02:00:00', '2026-10-28 05:30:00', 9_999, 99);

        $response = awayShow($driver, ['X-Locale' => 'en'])->assertOk();
        $cars = $response->json('data.cars');

        expect(array_column($cars, 'earned_cents'))->toBe([1_500, 1_000])
            ->and(array_column($cars, 'name'))->toBe(['Bizon M-2', 'Rdzak GT-4'])
            ->and(array_column($cars, 'played'))->toBe([false, true])
            ->and(array_column($cars, 'laps'))->toBe([5, 20])
            ->and($response->json('data.earned_cents'))->toBe(2_500)
            ->and($response->json('data.from_ms'))->toBe(awayMs('2026-10-28 06:00:00'))
            ->and($response->json('data.to_ms'))->toBe(awayMs('2026-10-28 11:00:00'))
            ->and($response->json('data.away_minutes'))->toBe(300);
    });

    it('say that a car waits for the driver when it has no fuel or has broken down, also when it earned nothing', function () {
        $driver = awayDriver();
        awayCar($driver, 'fuel_empty');
        awayCar($driver, 'breakdown', 'bizon-m-2');
        awayCar($driver, 'parked', 'kuna-4x4');
        awayCar($driver, 'travel', 'rdzak-gt-2');

        $cars = awayShow($driver)->assertOk()->json('data.cars');

        // A parked car and one on the road earned nothing and wait for nothing: they are left out.
        expect(array_column($cars, 'waiting'))->toEqualCanonicalizing(['fuel_empty', 'breakdown'])
            ->and($cars)->toHaveCount(2);
    });

    it('are the driver\'s own, and no other driver\'s', function () {
        $driver = awayDriver();
        $stranger = awayDriver();
        awaySettled(awayCar($stranger), '2026-10-28 06:30:00', '2026-10-28 10:30:00', 7_000, 30);

        expect(awayShow($driver)->assertOk()->json('data.cars'))->toBe([]);
    });
});

describe('the jobs and the journeys', function () {
    it('list the jobs of the workshop that were done in the break, with the part, and not the others', function () {
        $driver = awayDriver();
        $tires = Part::factory()->state(['part_slug' => 'street-tires-t2', 'slot' => 'tires'])->create(['user_id' => $driver->id]);

        foreach ([['upgrade', '2026-10-28 08:00:00'], ['mount', '2026-10-28 09:30:00'], ['repair', '2026-10-28 11:30:00'], ['mount', '2026-10-28 05:00:00']] as [$kind, $finished]) {
            WorkshopJob::query()->forceCreate(['user_id' => $driver->id, 'part_id' => $tires->id, 'kind' => $kind, 'status' => WorkshopJob::STATUS_DONE, 'start_ms' => awayMs($finished) - 60_000, 'finish_ms' => awayMs($finished)]);
        }

        // A job that is not done is not told.
        WorkshopJob::query()->forceCreate(['user_id' => $driver->id, 'part_id' => $tires->id, 'kind' => 'upgrade', 'status' => WorkshopJob::STATUS_QUEUED, 'start_ms' => awayMs('2026-10-28 13:00:00'), 'finish_ms' => awayMs('2026-10-28 13:10:00')]);

        $jobs = awayShow($driver, ['X-Locale' => 'en'])->assertOk()->json('data.jobs');

        expect(array_column($jobs, 'kind'))->toBe(['upgrade', 'mount'])
            ->and(array_column($jobs, 'part'))->toBe(['Street tires', 'Street tires'])
            ->and($jobs[0]['finished_ms'])->toBe(awayMs('2026-10-28 08:00:00'));
    });

    it('list the journeys that ended in the break, with the fine that the police got when the driver did not choose, and nothing of a stop that was waived', function () {
        $driver = awayDriver();
        $run = awayCar($driver);

        $journey = fn (string $arrived, array $more = []) => Travel::query()->forceCreate([
            'user_id' => $driver->id, 'race_run_id' => $run->id, 'from_region' => 'zlomowo', 'to_region' => 'kruszyn', 'distance_km' => 38, 'fuel_ml' => 2_280,
            'start_ms' => awayMs($arrived) - 1_000_000, 'arrive_ms' => awayMs($arrived), 'status' => Travel::STATUS_ARRIVED, ...$more,
        ]);

        $journey('2026-10-28 07:00:00', ['boosted' => true, 'police_at_ms' => awayMs('2026-10-28 06:40:00'), 'police_fine_cents' => 1_250, 'police_choice' => 'default', 'police_outcome' => 'defaulted', 'police_paid_cents' => 1_250]);
        $journey('2026-10-28 09:00:00', ['boosted' => true, 'police_at_ms' => awayMs('2026-10-28 08:40:00'), 'police_choice' => 'waived', 'police_outcome' => 'waived']);
        $journey('2026-10-28 10:00:00');
        // Before the break.
        $journey('2026-10-28 05:00:00');

        $journeys = awayShow($driver, ['X-Locale' => 'en'])->assertOk()->json('data.journeys');

        expect(array_column($journeys, 'to'))->toBe(['Kruszyn', 'Kruszyn', 'Kruszyn'])
            ->and(array_column($journeys, 'boosted'))->toBe([true, true, false])
            ->and($journeys[0]['police'])->toBe(['outcome' => 'defaulted', 'paid_cents' => 1_250])
            ->and($journeys[1]['police'])->toBeNull()
            ->and($journeys[2]['police'])->toBeNull();
    });
});

describe('the reach', function () {
    it('goes back a week at most, whatever the break was', function () {
        $driver = awayDriver(['away_from' => '2026-10-01 08:00:00']);

        $response = awayShow($driver)->assertOk();

        expect($response->json('data.from_ms'))->toBe(awayMs('2026-10-21 11:00:00'))
            ->and($response->json('data.away_minutes'))->toBe(7 * 24 * 60);
    });

    it('is the minimum of a quarter of an hour, and says it', function () {
        expect(AwaySummary::MIN_AWAY_MINUTES)->toBe(15)->and(AwaySummary::MAX_DAYS)->toBe(7);
    });
});
