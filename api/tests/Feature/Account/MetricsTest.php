<?php

declare(strict_types=1);

use App\Models\RaceRun;
use App\Models\Travel;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\UserVisit;
use App\Models\WorkshopJob;
use App\Services\AccountErasure;
use App\Services\AccountExport;
use App\Services\Metrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// The numbers of the alpha, worked out from when the drivers came: a visit is written down on the first request after a break of ten minutes, and the report tells
// whether they come back the day after and a week after, how long a session lasts and how fast the first minutes lead to an upgrade and a journey.

afterEach(fn () => Carbon::setTestNow());

const METRICS_NOW = '2026-10-28 12:00:00';

function metricsNow(): CarbonImmutable
{
    return CarbonImmutable::parse(METRICS_NOW);
}

/** An account made at `$made`, with the visits at the times given. @param  list<string>  $visits */
function metricsDriver(string $made, array $visits = [], bool $guest = true): User
{
    $user = ($guest ? User::factory()->guest() : User::factory())->create(['created_at' => $made]);
    $previous = null;

    foreach ($visits as $at) {
        UserVisit::query()->create(['user_id' => $user->id, 'at' => $at, 'gap_minutes' => $previous === null ? null : (int) CarbonImmutable::parse($previous)->diffInMinutes($at)]);
        $previous = $at;
    }

    return $user;
}

function metricsReport(string $from = '2026-10-01', string $to = '2026-10-29'): array
{
    return (new Metrics)->report(CarbonImmutable::parse($from), CarbonImmutable::parse($to), metricsNow());
}

describe('the visits', function () {
    it('are written down on the first request after a break of ten minutes, and not on the ones in between', function () {
        $user = User::factory()->guest()->create(['last_seen_at' => null]);
        Carbon::setTestNow('2026-10-28 10:00:00');
        $this->getJson('/api/v1/starter-cars', bearerFor($user));
        Carbon::setTestNow('2026-10-28 10:05:00');
        $this->getJson('/api/v1/starter-cars', bearerFor($user));
        Carbon::setTestNow('2026-10-28 10:20:00');
        $this->getJson('/api/v1/starter-cars', bearerFor($user));
        Carbon::setTestNow('2026-10-28 15:20:00');
        $this->getJson('/api/v1/starter-cars', bearerFor($user));

        $visits = UserVisit::query()->where('user_id', $user->id)->orderBy('id')->get();

        expect($visits->pluck('at')->map(fn ($at) => $at->format('H:i'))->all())->toBe(['10:00', '10:20', '15:20'])
            ->and($visits->pluck('gap_minutes')->all())->toBe([null, 20, 300]);
    });

    it('are in the export of the account and go with the account when it is erased', function () {
        $user = metricsDriver('2026-10-20 10:00:00', ['2026-10-20 10:00:00', '2026-10-21 18:00:00']);
        $other = metricsDriver('2026-10-20 10:00:00', ['2026-10-20 11:00:00']);

        $visits = (new AccountExport)->for($user)['game']['visits'];

        expect($visits)->toHaveCount(2)
            ->and($visits[1]['gap_minutes'])->toBe(1_920);

        $removed = (new AccountErasure)->erase($user);

        expect($removed['visits'])->toBe(2)
            ->and(UserVisit::query()->pluck('user_id')->all())->toBe([$other->id]);
    });
});

describe('the retention', function () {
    it('is told for the accounts for which the day is over, by whether the driver came on that day', function () {
        // Made on the 20th: D1 is the 21st, D7 the 27th (over); D3 the 23rd.
        metricsDriver('2026-10-20 10:00:00', ['2026-10-20 10:00:00', '2026-10-21 08:00:00', '2026-10-27 22:00:00']);
        metricsDriver('2026-10-20 12:00:00', ['2026-10-20 12:00:00', '2026-10-23 09:00:00']);
        metricsDriver('2026-10-20 13:00:00', ['2026-10-20 13:00:00']);
        // Made on the 27th: D1 is the 28th, which is not over at noon, so the account is not counted; D7 is not yet.
        metricsDriver('2026-10-27 09:00:00', ['2026-10-27 09:00:00', '2026-10-28 09:00:00']);

        $retention = metricsReport()['retention'];

        expect($retention['d1'])->toBe(['eligible' => 3, 'returned' => 1, 'rate' => 0.3333])
            ->and($retention['d3'])->toBe(['eligible' => 3, 'returned' => 1, 'rate' => 0.3333])
            ->and($retention['d7'])->toBe(['eligible' => 3, 'returned' => 1, 'rate' => 0.3333])
            ->and($retention['d2'])->toBe(['eligible' => 3, 'returned' => 0, 'rate' => 0.0]);
    });

    it('has no rate when no account is old enough', function () {
        metricsDriver('2026-10-28 09:00:00', ['2026-10-28 09:00:00']);

        $retention = metricsReport()['retention'];

        expect($retention['d1'])->toBe(['eligible' => 0, 'returned' => 0, 'rate' => null]);
    });

    it('counts only the accounts that were made in the period', function () {
        metricsDriver('2026-09-01 10:00:00', ['2026-09-01 10:00:00', '2026-09-02 10:00:00']);
        metricsDriver('2026-10-10 10:00:00', ['2026-10-10 10:00:00']);

        $report = metricsReport();

        expect($report['cohort']['accounts'])->toBe(1)
            ->and($report['retention']['d1']['eligible'])->toBe(1)
            ->and($report['retention']['d1']['returned'])->toBe(0);
    });
});

describe('the sessions', function () {
    it('join the visits that are less than half an hour apart, and last from the first to the last and a visit more', function () {
        // One session of 10:00-10:40 (visits every ten minutes: 40 minutes and a visit of ten), another at 15:00 (ten minutes), another the next day.
        metricsDriver('2026-10-20 09:00:00', ['2026-10-20 10:00:00', '2026-10-20 10:10:00', '2026-10-20 10:20:00', '2026-10-20 10:40:00', '2026-10-20 15:00:00', '2026-10-21 08:00:00']);
        metricsDriver('2026-10-20 09:00:00', ['2026-10-20 09:00:00']);

        $sessions = metricsReport()['sessions'];

        expect($sessions['drivers'])->toBe(2)
            ->and($sessions['sessions'])->toBe(4)
            ->and($sessions['per_driver'])->toBe(2.0)
            // 50, 10, 10 and 10 minutes: the median is 10.
            ->and($sessions['median_minutes'])->toBe(10.0);
    });

    it('are told for no drivers when nobody came', function () {
        metricsDriver('2026-10-20 09:00:00');

        $sessions = metricsReport()['sessions'];

        expect($sessions)->toBe(['drivers' => 0, 'sessions' => 0, 'per_driver' => null, 'median_minutes' => null]);
    });
});

describe('the first minutes', function () {
    it('are told by how many drivers queued an upgrade and sent a car to another city, how soon, and how many did it within the targets', function () {
        $quick = metricsDriver('2026-10-20 10:00:00');
        $slow = metricsDriver('2026-10-20 10:00:00');
        $never = metricsDriver('2026-10-20 10:00:00');

        // The first upgrade: 2 minutes after the account for one, 10 for the other (a later one of the same driver does not count).
        foreach ([[$quick, '2026-10-20 10:02:00'], [$slow, '2026-10-20 10:10:00'], [$slow, '2026-10-20 10:30:00']] as [$user, $at]) {
            WorkshopJob::query()->forceCreate(['user_id' => $user->id, 'kind' => WorkshopJob::KIND_UPGRADE, 'status' => 'done', 'start_ms' => 0, 'finish_ms' => 1, 'created_at' => $at, 'updated_at' => $at]);
        }

        Travel::query()->forceCreate(['user_id' => $quick->id, 'race_run_id' => RaceRun::factory()->create(['user_id' => $quick->id])->id, 'from_region' => 'zlomowo', 'to_region' => 'kruszyn', 'distance_km' => 38, 'fuel_ml' => 2_280, 'start_ms' => 0, 'arrive_ms' => 1, 'status' => 'arrived', 'created_at' => '2026-10-20 10:25:00', 'updated_at' => '2026-10-20 10:25:00']);
        Travel::query()->forceCreate(['user_id' => $slow->id, 'race_run_id' => RaceRun::factory()->create(['user_id' => $slow->id])->id, 'from_region' => 'zlomowo', 'to_region' => 'kruszyn', 'distance_km' => 38, 'fuel_ml' => 2_280, 'start_ms' => 0, 'arrive_ms' => 1, 'status' => 'arrived', 'created_at' => '2026-10-20 11:00:00', 'updated_at' => '2026-10-20 11:00:00']);

        $steps = metricsReport()['first_steps'];

        expect($steps['first_upgrade'])->toBe(['reached' => 2, 'rate' => 0.6667, 'median_minutes' => 6.0, 'within_target' => 1, 'target_minutes' => 3])
            ->and($steps['first_journey'])->toBe(['reached' => 2, 'rate' => 0.6667, 'median_minutes' => 42.5, 'within_target' => 1, 'target_minutes' => 30]);

        expect($never->id)->not->toBeNull();
    });

    it('count the steps of the tutorial that a driver finished', function () {
        $far = metricsDriver('2026-10-20 10:00:00');
        $near = metricsDriver('2026-10-20 10:00:00');
        metricsDriver('2026-10-20 10:00:00');

        foreach (range(1, 12) as $step) {
            TutorialProgress::query()->forceCreate(['user_id' => $far->id, 'step_key' => "step-{$step}", 'state' => 'done']);
        }

        foreach (range(1, 5) as $step) {
            TutorialProgress::query()->forceCreate(['user_id' => $near->id, 'step_key' => "step-{$step}", 'state' => 'done']);
        }

        // A step that was skipped is not a step that was finished.
        TutorialProgress::query()->forceCreate(['user_id' => $near->id, 'step_key' => 'step-9', 'state' => 'skipped']);

        $tutorial = metricsReport()['tutorial'];

        expect($tutorial['steps_5'])->toBe(['reached' => 2, 'rate' => 0.6667])
            ->and($tutorial['steps_10'])->toBe(['reached' => 1, 'rate' => 0.3333])
            ->and($tutorial['steps_20'])->toBe(['reached' => 0, 'rate' => 0.0]);
    });
});

describe('the days', function () {
    it('tell who came on each of the last fourteen days, and how many of them were made that day', function () {
        metricsDriver('2026-10-27 08:00:00', ['2026-10-27 08:00:00', '2026-10-28 09:00:00']);
        metricsDriver('2026-10-28 08:00:00', ['2026-10-28 08:00:00']);
        metricsDriver('2026-10-01 08:00:00', ['2026-10-28 11:00:00']);

        $activity = collect(metricsReport()['activity'])->keyBy('day');

        expect($activity)->toHaveCount(14)
            ->and($activity->keys()->first())->toBe('2026-10-15')
            ->and($activity->keys()->last())->toBe('2026-10-28')
            ->and($activity['2026-10-27'])->toBe(['day' => '2026-10-27', 'active' => 1, 'new' => 1, 'returning' => 0])
            ->and($activity['2026-10-28'])->toBe(['day' => '2026-10-28', 'active' => 3, 'new' => 1, 'returning' => 2])
            ->and($activity['2026-10-20']['active'])->toBe(0);
    });
});

describe('the command', function () {
    it('prints the report, and as JSON when asked', function () {
        Carbon::setTestNow(METRICS_NOW);
        metricsDriver('2026-10-20 10:00:00', ['2026-10-20 10:00:00', '2026-10-21 08:00:00']);

        $this->artisan('metrics:report', ['--days' => 30])
            ->expectsOutputToContain('Accounts made from')
            ->expectsOutputToContain('Retention')
            ->assertSuccessful();

        $this->artisan('metrics:report', ['--days' => 30, '--json' => true])->expectsOutputToContain('"retention"')->assertSuccessful();
    });

    it('refuses a number of days that is not one', function () {
        $this->artisan('metrics:report', ['--days' => '0'])->assertFailed();
        $this->artisan('metrics:report', ['--days' => 'week'])->assertFailed();
    });
});
