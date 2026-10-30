<?php

declare(strict_types=1);

use App\Content\ContentSource;
use App\Content\MissionCatalog;
use App\Content\PackValidator;
use App\Models\CrateOpening;
use App\Models\RaceRun;
use App\Models\UserMission;
use App\Models\UserMissionPeriod;
use App\Services\AccountErasure;
use App\Services\AccountExport;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../../Support/MissionHelpers.php';

// The missions as content: the document of the pack and the numbers of the rules, what the validator says of them, what the shipped pools come to for every level
// and for a car, and what the commands and the account do with the rows.

/** @return list<string> the file and the place of every error the validator finds in the content */
function missionErrors(ContentSource $content): array
{
    return array_map(static fn (array $issue): string => "{$issue['file']} {$issue['pointer']}", PackValidator::fromConfig()->validateSource($content)->errors());
}

/** The content of the repository with a change made to the missions document. */
function withMissions(Closure $change): ContentSource
{
    return contentWith(['rules/missions' => $change]);
}

describe('the missions of the pack', function () {
    it('are valid in the pack that ships, with six missions in the day, five in the week and four in the month', function () {
        $catalog = MissionCatalog::fromSource(repositoryContent());

        expect(missionErrors(repositoryContent()))->toBe([])
            ->and(count($catalog->slots('daily')))->toBe(6)
            ->and(count($catalog->slots('weekly')))->toBe(5)
            ->and(count($catalog->slots('monthly')))->toBe(4);
    });

    it('have a mission for every slot at every level of the game', function () {
        $catalog = MissionCatalog::fromSource(repositoryContent());
        $levels = repositoryDocument('rules/starter')['player']['max_level'];

        foreach (MissionCatalog::PERIODS as $period) {
            foreach ($catalog->slots($period) as $slot) {
                foreach (range(1, $levels) as $level) {
                    $fitting = array_filter($slot['templates'], static fn (array $template): bool => $level >= ($template['min_player_level'] ?? 1) && $level <= ($template['max_player_level'] ?? PHP_INT_MAX));

                    expect($fitting)->not->toBe([], "{$period} {$slot['key']} has no template at level {$level}.");
                }
            }
        }
    });

    it('refuse a slot that leaves a level without a mission', function () {
        $content = withMissions(function (array $missions): array {
            $missions['periodic']['daily'][3]['templates'] = ['only-high' => [...$missions['periodic']['daily'][3]['templates']['daily-mount'], 'min_player_level' => 5]];

            return $missions;
        });

        expect(missionErrors($content))->toBe(['rules/missions.json /periodic/daily/3']);
    });

    it('refuse a goal of the wrong kind, a goal that only a chain can count, and a key used twice', function () {
        $content = withMissions(function (array $missions): array {
            $slot = &$missions['periodic']['daily'][0]['templates'];
            $slot['daily-laps'] = ['objective' => 'laps', 'weight' => 1, 'reward_share' => 1, 'count' => 5];
            $slot['daily-level'] = ['objective' => 'player_level', 'count' => 5, 'weight' => 1, 'reward_share' => 1];
            $missions['periodic']['weekly'][0]['templates']['daily-laps-long'] = ['objective' => 'laps', 'tanks_ppm' => 100_000, 'weight' => 1, 'reward_share' => 1];
            unset($slot);

            return $missions;
        });
        $errors = missionErrors($content);

        expect($errors)->toContain('rules/missions.json /periodic/daily/0/templates/daily-laps')
            ->and($errors)->toContain('rules/missions.json /periodic/daily/0/templates/daily-level/objective')
            ->and($errors)->toContain('rules/missions.json /periodic/weekly/0/templates/daily-laps-long');
    });

    it('refuse a time zone that does not exist and a step of the tutorial that is not there', function () {
        $content = contentWith(['rules/starter' => function (array $rules): array {
            $rules['missions']['timezone'] = 'Mars/Olympus';
            $rules['missions']['unlock_after_step'] = 'nonexistent';

            return $rules;
        }]);

        expect(missionErrors($content))->toContain('rules/starter.json /missions/timezone')->toContain('rules/starter.json /missions/unlock_after_step');
    });

    it('cannot pay more coins than the caps of the schema', function () {
        $content = contentWith(['rules/starter' => function (array $rules): array {
            $rules['missions']['periods']['daily']['bonus_coins'] = 6;
            $rules['missions']['periods']['monthly']['bonus_coins'] = 61;

            return $rules;
        }]);

        expect(missionErrors($content))->toContain('rules/starter.json /missions/periods/daily/bonus_coins');

        $content = contentWith(['rules/starter' => function (array $rules): array {
            $rules['missions']['periods']['monthly']['bonus_coins'] = 61;

            return $rules;
        }]);

        expect(missionErrors($content))->toContain('rules/starter.json /missions/periods/monthly/bonus_coins');
    });
});

describe('the budget', function () {
    it('is shown by the command for a car and a level, with a month of everything next to the prices of the game', function () {
        $this->artisan('missions:budget', ['--level' => 12])
            ->expectsOutputToContain('DAILY: 6 missions')
            ->expectsOutputToContain('WEEKLY: 5 missions')
            ->expectsOutputToContain('MONTHLY: 4 missions')
            ->expectsOutputToContain('119 coins')
            ->expectsOutputToContain('The VIP account costs 150 coins: a month of doing everything is 80% of it.')
            ->assertSuccessful();
    });

    it('stays within what the owner approved for the economy: about a tenth of the cash and under a tenth of the XP of a month of tanks', function () {
        $rules = repositoryDocument('rules/starter')['missions']['periods'];

        // In parts of a tank (1.0 is one tank): the day, the week and the month, over the 30 tanks that a driver who looks in once a day fills in a month.
        $cash = (30 * $rules['daily']['cash_ppm'] + (30 / 7) * $rules['weekly']['cash_ppm'] + $rules['monthly']['cash_ppm']) / 1_000_000 / 30;
        $xp = (30 * $rules['daily']['xp_ppm'] + (30 / 7) * $rules['weekly']['xp_ppm'] + $rules['monthly']['xp_ppm']) / 1_000_000 / 30;

        expect($cash)->toBeBetween(0.09, 0.12)->and($xp)->toBeBetween(0.06, 0.09);
    });
});

describe('the rows', function () {
    beforeEach(fn () => theMissionsAre());

    function rowOf(int $driver, string $kind, string $key, array $extra = []): UserMission
    {
        return UserMission::query()->create([...[
            'user_id' => $driver, 'kind' => $kind, 'period_key' => $key, 'slot' => 1, 'mission_key' => 't', 'objective' => 'laps', 'target' => 5, 'progress' => 5,
            'reward' => ['cash_cents' => 100], 'window_from_ms' => 1, 'window_to_ms' => 2, 'closed_at' => now(),
        ], ...$extra]);
    }

    it('are removed when the period is long over, except a mission that was done and is waiting to be taken', function () {
        $driver = missionDriver();
        Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00', 'Europe/Warsaw'));
        $now = Carbon::now()->getTimestampMs();
        $old = Carbon::now()->subDays(40)->getTimestampMs();

        $taken = rowOf($driver->id, 'daily', '2026-09-20', ['window_to_ms' => $old, 'completed_at' => now(), 'claimed_at' => now()]);
        $missed = rowOf($driver->id, 'daily', '2026-09-21', ['window_to_ms' => $old, 'slot' => 2]);
        $waiting = rowOf($driver->id, 'daily', '2026-09-22', ['window_to_ms' => $old, 'completed_at' => now(), 'slot' => 3]);
        $recent = rowOf($driver->id, 'daily', '2026-10-20', ['window_to_ms' => $now - 3_600_000, 'claimed_at' => now(), 'completed_at' => now(), 'slot' => 4]);
        $main = rowOf($driver->id, 'main', 'driver', ['window_from_ms' => null, 'window_to_ms' => null, 'closed_at' => null, 'completed_at' => now(), 'claimed_at' => now(), 'slot' => 5]);

        $this->artisan('missions:prune')->expectsOutputToContain('Removed 2 missions')->assertSuccessful();

        expect(UserMission::query()->whereKey([$taken->id, $missed->id])->count())->toBe(0)
            ->and(UserMission::query()->whereKey([$waiting->id, $recent->id, $main->id])->count())->toBe(3);
    });

    it('are in the copy of the data of the account, and are removed with the account', function () {
        $driver = missionDriver();
        missionsOf($driver);

        $game = app(AccountExport::class)->for($driver)['game'];

        expect($game['missions'])->toHaveCount(4)
            ->and($game['missions'][0])->not->toHaveKey('user_id')
            ->and($game['mission_sets'])->toHaveCount(3);

        $removed = app(AccountErasure::class)->erase($driver);

        expect($removed['missions'])->toBe(4)->and($removed['mission sets'])->toBe(3)
            ->and(UserMission::query()->where('user_id', $driver->id)->count())->toBe(0)
            ->and(UserMissionPeriod::query()->where('user_id', $driver->id)->count())->toBe(0);
    });
});

describe('the crates that missions give', function () {
    beforeEach(fn () => theMissionsAre());

    it('have a count of their own for the guarantees, and do not move the guarantees of the shop', function () {
        $driver = missionDriver();
        CrateOpening::query()->create(['user_id' => $driver->id, 'kind' => 'slot', 'pity_group' => 'standard', 'opening_index' => 0, 'slot' => 'tires', 'tier' => 1, 'rarity' => 1, 'part_slug' => 'street-tires-t2', 'price_cents' => 100]);

        // Taking the service mission opens a crate of the stream of the missions.
        missionsOf($driver);
        doneJob($driver);
        $mission = collect(missionsOf($driver)->json('data.periods.daily.missions'))->firstWhere('key', 't-service');
        claimMission($driver, $mission['id'])->assertOk();

        expect(CrateOpening::query()->where('user_id', $driver->id)->where('pity_group', 'm:standard')->pluck('opening_index')->all())->toBe([0])
            ->and(CrateOpening::query()->where('user_id', $driver->id)->where('pity_group', 'standard')->count())->toBe(1)
            // A run in the garage is what the driver has: the crate did not change it.
            ->and(RaceRun::query()->where('user_id', $driver->id)->count())->toBe(1);
    });
});
