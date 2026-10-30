<?php

declare(strict_types=1);

use App\Models\CrateOpening;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\UserMission;
use App\Models\UserMissionPeriod;
use App\Models\Wallet;
use App\Services\Ledger;
use App\Services\MissionPeriods;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../../Support/MissionHelpers.php';

// The sets of missions: drawn once for the period, counted out of the facts of the game (also what happened while the driver was away), taken once, and paid through the
// ledger. They run on pools of their own, so that they say what the engine does and not what the content asks for; the content has its own tests at the end.

afterEach(fn () => Carbon::setTestNow());

// The pools of the tests, unless a test says otherwise.
beforeEach(fn () => theMissionsAre());

describe('the periods', function () {
    it('starts the day at the reset hour of the game, in its time zone, and the week on Monday', function () {
        $periods = app(MissionPeriods::class);

        $evening = $periods->at('daily', Carbon::parse('2026-10-30 23:30:00', 'Europe/Warsaw'));
        $afterMidnight = $periods->at('daily', Carbon::parse('2026-10-31 01:00:00', 'Europe/Warsaw'));
        $morning = $periods->at('daily', Carbon::parse('2026-10-31 04:00:00', 'Europe/Warsaw'));
        $justBefore = $periods->at('daily', Carbon::parse('2026-10-31 03:59:59', 'Europe/Warsaw'));

        expect($evening['key'])->toBe('2026-10-30')
            ->and($afterMidnight)->toBe($evening)
            ->and($justBefore)->toBe($evening)
            ->and($morning['key'])->toBe('2026-10-31')
            ->and($evening['to_ms'])->toBe($morning['from_ms'])
            ->and($evening['from_ms'])->toBe(Carbon::parse('2026-10-30 04:00:00', 'Europe/Warsaw')->getTimestampMs());

        // 2026-10-30 is a Friday: the week of the missions began on Monday the 26th at four in the morning and lasts until the next Monday.
        $week = $periods->at('weekly', Carbon::parse('2026-10-30 12:00:00', 'Europe/Warsaw'));
        $month = $periods->at('monthly', Carbon::parse('2026-10-30 12:00:00', 'Europe/Warsaw'));

        expect($week['key'])->toBe('2026-W44')
            ->and($week['from_ms'])->toBe(Carbon::parse('2026-10-26 04:00:00', 'Europe/Warsaw')->getTimestampMs())
            ->and($week['to_ms'])->toBe(Carbon::parse('2026-11-02 04:00:00', 'Europe/Warsaw')->getTimestampMs())
            ->and($month['key'])->toBe('2026-10')
            ->and($month['from_ms'])->toBe(Carbon::parse('2026-10-01 04:00:00', 'Europe/Warsaw')->getTimestampMs())
            ->and($month['to_ms'])->toBe(Carbon::parse('2026-11-01 04:00:00', 'Europe/Warsaw')->getTimestampMs());

        // The first of the month before four in the morning is still the month before.
        expect($periods->at('monthly', Carbon::parse('2026-11-01 03:00:00', 'Europe/Warsaw'))['key'])->toBe('2026-10')
            ->and($periods->at('weekly', Carbon::parse('2026-11-02 03:00:00', 'Europe/Warsaw'))['key'])->toBe('2026-W44')
            ->and($periods->at('weekly', Carbon::parse('2026-11-02 04:00:00', 'Europe/Warsaw'))['key'])->toBe('2026-W45');
    });

    it('is not thrown by the days when the clocks change', function () {
        $periods = app(MissionPeriods::class);

        // The clocks go back at three in the night of 2026-10-25, so the day that starts at four on the 24th has 25 hours; they go forward on 2026-03-29, and the day that
        // starts on the 28th has 23.
        $back = $periods->at('daily', Carbon::parse('2026-10-24 12:00:00', 'Europe/Warsaw'));
        $forward = $periods->at('daily', Carbon::parse('2026-03-28 12:00:00', 'Europe/Warsaw'));
        $next = $periods->at('daily', Carbon::parse('2026-10-25 12:00:00', 'Europe/Warsaw'));

        expect(($back['to_ms'] - $back['from_ms']) / 3_600_000)->toEqual(25)
            ->and(($forward['to_ms'] - $forward['from_ms']) / 3_600_000)->toEqual(23)
            ->and($back['to_ms'])->toBe($next['from_ms'])
            ->and($next['key'])->toBe('2026-10-25');
    });
});

describe('the sets', function () {
    it('are drawn the first time the driver looks, once, and a look again changes nothing', function () {
        $driver = missionDriver();

        $first = missionsOf($driver)->assertOk();
        $second = missionsOf($driver);

        expect($first->json('data.available'))->toBeTrue()
            ->and($first->json('data.locked'))->toBeNull()
            ->and(array_column($first->json('data.periods.daily.missions'), 'key'))->toBe(['t-laps', 't-service'])
            ->and($first->json('data.periods.daily.key'))->toBe('2026-10-30')
            ->and($first->json('data.periods.weekly.key'))->toBe('2026-W44')
            ->and($first->json('data.periods.monthly.key'))->toBe('2026-10')
            ->and($first->json('data.periods.daily.total'))->toBe(2)
            ->and($second->json('data.periods.daily.missions'))->toBe($first->json('data.periods.daily.missions'))
            ->and(UserMission::query()->where('user_id', $driver->id)->count())->toBe(4)
            ->and(UserMissionPeriod::query()->where('user_id', $driver->id)->count())->toBe(3);
    });

    it('keep what they were drawn with when the rules change in the middle of the period', function () {
        $driver = missionDriver();
        $before = missionsOf($driver)->json('data.periods.daily.missions');

        theMissionsAre(daily: [['key' => 'drive', 'templates' => ['t-other' => missionTemplate('laps', ['tanks_ppm' => 900_000])]]]);

        expect(missionsOf($driver)->json('data.periods.daily.missions'))->toBe($before);
    });

    it('are the same for the same driver and the period whoever asks, and the draw is made of the templates that fit the level', function () {
        theMissionsAre(daily: [['key' => 'drive', 'templates' => [
            'low' => missionTemplate('mount', ['count' => 1, 'max_player_level' => 5]),
            'high' => missionTemplate('upgrade', ['count' => 1, 'min_player_level' => 6]),
        ]]]);
        $driver = missionDriver();

        // A new driver is at level 1: only the template for the low levels fits.
        expect(array_column(missionsOf($driver)->json('data.periods.daily.missions'), 'key'))->toBe(['low']);
    });

    it('wait for the tutorial and for a car', function () {
        Carbon::setTestNow(missionNoon());
        $beginner = User::factory()->guest()->create();
        $walker = missionDriver(started: false);

        expect(missionsOf($beginner)->json('data.locked'))->toBe('tutorial')
            ->and(missionsOf($beginner)->json('data.periods.daily.drawn'))->toBeFalse()
            ->and(missionsOf($walker)->json('data.locked'))->toBeNull()
            ->and(missionsOf($walker)->json('data.periods.daily.drawn'))->toBeFalse()
            ->and(UserMission::query()->count())->toBe(0);

        // Leaving the tutorial opens them.
        TutorialProgress::query()->create(['user_id' => $beginner->id, 'step_key' => 'wreck', 'state' => TutorialProgress::STATE_SKIPPED]);
        RaceRun::factory()->create(['user_id' => $beginner->id, 'cursor_ms' => Carbon::now()->getTimestampMs()]);

        expect(missionsOf($beginner)->json('data.periods.daily.drawn'))->toBeTrue();
    });

    it('are not there when the game has no missions, and nothing can be taken', function () {
        missionRules(function (array $data): array {
            $data['missions']['enabled'] = false;

            return $data;
        });
        $driver = missionDriver();

        expect(missionsOf($driver)->json('data.available'))->toBeFalse()
            ->and(claimMission($driver, 1)->assertStatus(422)->json('code'))->toBe('missions_unavailable')
            ->and(UserMission::query()->count())->toBe(0);
    });

    it('divide what the set pays among its missions by their shares, so that it pays the same whatever is drawn', function () {
        theMissionsAre(daily: [
            ['key' => 'a', 'templates' => ['t-a' => missionTemplate('laps', ['tanks_ppm' => 200_000, 'reward_share' => 3])]],
            ['key' => 'b', 'templates' => ['t-b' => missionTemplate('service', ['count' => 1, 'reward_share' => 1])]],
        ]);
        $driver = missionDriver();

        $missions = missionsOf($driver)->json('data.periods.daily.missions');
        $cash = array_column(array_column($missions, 'reward'), 'cash_cents');
        $xp = array_column(array_column($missions, 'reward'), 'xp');

        // Three parts to one, to the whole dollar.
        expect($cash[0])->toBeGreaterThan($cash[1])
            ->and($cash[0] % 100)->toBe(0)
            ->and(abs($cash[0] - 3 * $cash[1]))->toBeLessThanOrEqual(200)
            ->and($xp[0])->toBeGreaterThan($xp[1]);
    });
});

describe('the progress', function () {
    it('counts the laps that were driven while the driver was away, in the day they were driven in', function () {
        $driver = missionDriver();
        missionsOf($driver);

        // The car drives its tank (30 laps of 75 s) in the next hour, and the driver comes back two hours after that.
        Carbon::setTestNow(missionNoon()->addHours(3));
        $laps = collect(missionsOf($driver)->json('data.periods.daily.missions'))->firstWhere('key', 't-laps');

        expect($laps['progress'])->toBe($laps['target'])
            ->and($laps['status'])->toBe('completed')
            ->and(RaceRun::query()->where('user_id', $driver->id)->value('laps_done'))->toBe(30);
    });

    it('counts a lap in the window it was driven in even when it was settled after the day had ended', function () {
        $driver = missionDriver();
        // The day of the missions ends at four in the morning; the car was sent out at midnight and drives 30 laps in 37 minutes.
        Carbon::setTestNow(Carbon::parse('2026-10-31 00:00:00', 'Europe/Warsaw'));
        RaceRun::query()->where('user_id', $driver->id)->update(['cursor_ms' => Carbon::now()->getTimestampMs()]);
        missionsOf($driver);

        // The driver looks in again at noon of the next day: the laps were driven in the day that ended at four, and are counted for it, then taken for the driver.
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', 'Europe/Warsaw'));
        $state = missionsOf($driver);

        $closed = UserMission::query()->where('user_id', $driver->id)->where('kind', 'daily')->where('period_key', '2026-10-30')->where('objective', 'laps')->firstOrFail();

        expect($closed->progress)->toBeGreaterThanOrEqual($closed->target)
            ->and($closed->claimed_at)->not->toBeNull()
            ->and($closed->auto_claimed)->toBeTrue()
            ->and($state->json('data.periods.daily.key'))->toBe('2026-10-31')
            // The laps that were driven on the new day are not the laps of the old one.
            ->and(UserMission::query()->where('user_id', $driver->id)->where('period_key', '2026-10-31')->where('objective', 'laps')->value('progress'))->toBe(0);
    });

    it('is the same however many times it is asked for, and never goes down', function () {
        $driver = missionDriver();
        missionsOf($driver);

        Carbon::setTestNow(missionNoon()->addMinutes(20));
        $once = collect(missionsOf($driver)->json('data.periods.daily.missions'))->firstWhere('key', 't-laps')['progress'];
        $twice = collect(missionsOf($driver)->json('data.periods.daily.missions'))->firstWhere('key', 't-laps')['progress'];

        expect($once)->toBeGreaterThan(0)->and($twice)->toBe($once);

        // Asked about again later, the laps already counted stay counted (the target is the cap of what is shown).
        Carbon::setTestNow(missionNoon()->addHours(2));

        expect(collect(missionsOf($driver)->json('data.periods.daily.missions'))->firstWhere('key', 't-laps')['progress'])->toBeGreaterThanOrEqual($once);
    });

    it('counts the jobs of the workshop that the mechanic finished in the window, and not the ones that the tutorial arranged', function () {
        $driver = missionDriver();
        missionsOf($driver);

        doneJob($driver, extra: ['scripted' => true]);
        expect(collect(missionsOf($driver)->json('data.periods.daily.missions'))->firstWhere('key', 't-service')['status'])->toBe('open');

        doneJob($driver);
        // A service that was done yesterday is not today's.
        doneJob($driver, extra: ['finish_ms' => Carbon::now()->subDay()->getTimestampMs()]);

        $service = collect(missionsOf($driver)->json('data.periods.daily.missions'))->firstWhere('key', 't-service');

        expect($service['status'])->toBe('completed')->and($service['progress'])->toBe(1);
    });

    it('does not count the crates that the missions opened, nor what the missions paid', function () {
        theMissionsAre(daily: [
            ['key' => 'shop', 'templates' => ['t-crate' => missionTemplate('crate', ['count' => 1])]],
            ['key' => 'earn', 'templates' => ['t-cash' => missionTemplate('cash_earned', ['tanks_ppm' => 1_000])]],
        ]);
        $driver = missionDriver();
        missionsOf($driver);

        CrateOpening::query()->create(['user_id' => $driver->id, 'kind' => 'slot', 'pity_group' => 'm:standard', 'opening_index' => 0, 'slot' => 'tires', 'tier' => 1, 'rarity' => 1, 'part_slug' => 'street-tires-t2', 'price_cents' => 0]);
        (new Ledger)->post($driver->id, 5_000_000, 'mission', 'mission:999');

        $missions = collect(missionsOf($driver)->json('data.periods.daily.missions'));

        expect($missions->firstWhere('key', 't-crate')['progress'])->toBe(0)
            ->and($missions->firstWhere('key', 't-cash')['progress'])->toBe(0);

        CrateOpening::query()->create(['user_id' => $driver->id, 'kind' => 'slot', 'pity_group' => 'standard', 'opening_index' => 0, 'slot' => 'tires', 'tier' => 1, 'rarity' => 1, 'part_slug' => 'street-tires-t2', 'price_cents' => 100]);

        expect(collect(missionsOf($driver)->json('data.periods.daily.missions'))->firstWhere('key', 't-crate')['status'])->toBe('completed');
    });
});

describe('taking a mission', function () {
    it('pays the cash and the XP once, through the ledger, and a second request pays nothing', function () {
        $driver = missionDriver();
        missionsOf($driver);
        doneJob($driver);

        $mission = collect(missionsOf($driver)->json('data.periods.daily.missions'))->firstWhere('key', 't-service');
        $cash = cashOfDriver($driver);
        $xp = Wallet::query()->find($driver->id)->xp;

        $first = claimMission($driver, $mission['id'])->assertOk();
        $second = claimMission($driver, $mission['id'])->assertOk();

        expect($first->json('repeated'))->toBeFalse()
            ->and($first->json('claimed.cash_cents'))->toBe($mission['reward']['cash_cents'])
            ->and($first->json('claimed.xp'))->toBe($mission['reward']['xp'])
            ->and($second->json('repeated'))->toBeTrue()
            ->and(cashOfDriver($driver))->toBe($cash + $mission['reward']['cash_cents'])
            ->and(Wallet::query()->find($driver->id)->xp)->toBe($xp + $mission['reward']['xp'])
            ->and(LedgerEntry::query()->where('user_id', $driver->id)->where('reason', 'mission')->count())->toBe(1)
            ->and((new Ledger)->isConsistent($driver->id))->toBeTrue()
            ->and(collect($second->json('data.periods.daily.missions'))->firstWhere('key', 't-service')['status'])->toBe('claimed');
    });

    it('opens the crate it gives, from a count of its own, and tells the part', function () {
        $driver = missionDriver();
        missionsOf($driver);
        doneJob($driver);
        $mission = collect(missionsOf($driver)->json('data.periods.daily.missions'))->firstWhere('key', 't-service');

        $claimed = claimMission($driver, $mission['id'])->assertOk();

        expect($claimed->json('claimed.parts'))->toHaveCount(1)
            ->and($claimed->json('claimed.crates'))->toBe(['slot'])
            ->and($claimed->json('claimed.parts.0.rarity'))->toBeString()
            ->and(CrateOpening::query()->where('user_id', $driver->id)->get(['pity_group', 'price_cents'])->map->only(['pity_group', 'price_cents'])->all())->toBe([['pity_group' => 'm:standard', 'price_cents' => 0]])
            // The crate cost nothing and is not one the driver opened in the shop.
            ->and(LedgerEntry::query()->where('user_id', $driver->id)->where('reason', 'crate')->count())->toBe(0);
    });

    it('is refused for a mission that is not done, for another driver’s and for one that is not there', function () {
        $driver = missionDriver();
        missionsOf($driver);
        $mission = missionsOf($driver)->json('data.periods.daily.missions.0');
        $stranger = missionDriver();

        expect(claimMission($driver, $mission['id'])->assertStatus(422)->json('code'))->toBe('mission_not_completed')
            ->and(claimMission($stranger, $mission['id'])->assertStatus(422)->json('code'))->toBe('mission_not_found')
            ->and(claimMission($driver, 987_654)->assertStatus(422)->json('code'))->toBe('mission_not_found')
            ->and(cashOfDriver($driver))->toBe(0);
    });

    it('takes every mission of a kind that is done in one request', function () {
        $driver = missionDriver();
        missionsOf($driver);
        doneJob($driver);
        Carbon::setTestNow(missionNoon()->addHours(3));

        $all = $this->postJson('/api/v1/missions/claim', ['kind' => 'daily'], bearerFor($driver))->assertOk();
        $again = $this->postJson('/api/v1/missions/claim', ['kind' => 'all'], bearerFor($driver))->assertOk();

        // The laps of the week and of the month are done as well, and 'all' takes them.
        expect($all->json('claimed.missions'))->toHaveCount(2)
            ->and($again->json('claimed.missions'))->toHaveCount(2)
            ->and(UserMission::query()->where('user_id', $driver->id)->where('kind', 'daily')->whereNull('claimed_at')->count())->toBe(0)
            ->and(LedgerEntry::query()->where('user_id', $driver->id)->where('reason', 'mission')->count())->toBe(4);

        $this->postJson('/api/v1/missions/claim', ['kind' => 'nope'], bearerFor($driver))->assertStatus(422);
    });
});

describe('the bonus for a whole set', function () {
    function finishTheDay(User $driver): void
    {
        missionsOf($driver);
        doneJob($driver);
        Carbon::setTestNow(missionNoon()->addHours(3));
        missionsOf($driver);
    }

    it('is ready when every mission of the set is done, and pays the premium coins once', function () {
        $driver = missionDriver();
        missionsOf($driver);

        expect(missionsOf($driver)->json('data.periods.daily.bonus'))->toBe(['coins' => 2, 'state' => 'locked'])
            ->and(claimBonus($driver)->assertStatus(422)->json('code'))->toBe('mission_bonus_not_ready');

        doneJob($driver);
        Carbon::setTestNow(missionNoon()->addHours(3));
        $state = missionsOf($driver);

        expect($state->json('data.periods.daily.bonus.state'))->toBe('ready');

        $first = claimBonus($driver)->assertOk();
        $second = claimBonus($driver)->assertOk();

        expect($first->json('repeated'))->toBeFalse()
            ->and($first->json('bonus_coins'))->toBe(2)
            ->and($second->json('repeated'))->toBeTrue()
            ->and(coinsOfDriver($driver))->toBe(2)
            ->and(LedgerEntry::query()->where('user_id', $driver->id)->where('currency', 'premium')->get(['reason', 'delta'])->map->only(['reason', 'delta'])->all())->toBe([['reason' => 'mission_bonus', 'delta' => 2]])
            ->and((new Ledger)->isConsistent($driver->id, Ledger::PREMIUM))->toBeTrue()
            ->and($second->json('data.periods.daily.bonus.state'))->toBe('claimed');
    });

    it('is counted in what can be taken, with the missions that are done', function () {
        $driver = missionDriver();
        missionsOf($driver);
        expect(missionsOf($driver)->json('data.claimable'))->toBe(0);

        doneJob($driver);
        Carbon::setTestNow(missionNoon()->addHours(3));

        // Two missions of the day and the bonus of the day, and the laps of the week and of the month, each of which is a whole set with a bonus.
        expect(missionsOf($driver)->json('data.claimable'))->toBe(2 + 1 + (1 + 1) * 2);
    });

    it('is paid for the driver, once, when the period ends without them taking it', function () {
        $driver = missionDriver();
        finishTheDay($driver);
        $cash = cashOfDriver($driver);

        // Tomorrow at noon the day is over: the missions that were done are taken, with the bonus.
        Carbon::setTestNow(missionNoon()->addDay());
        $state = missionsOf($driver);

        expect(array_column($state->json('data.auto_collected'), 'type'))->toBe(['mission', 'mission', 'bonus'])
            ->and(coinsOfDriver($driver))->toBe(2)
            ->and(cashOfDriver($driver))->toBeGreaterThan($cash)
            ->and(UserMission::query()->where('user_id', $driver->id)->where('period_key', '2026-10-30')->where('kind', 'daily')->where('auto_claimed', true)->count())->toBe(2);

        $paid = cashOfDriver($driver);
        $after = missionsOf($driver);

        expect($after->json('data.auto_collected'))->toBe([])
            ->and(cashOfDriver($driver))->toBe($paid)
            ->and(coinsOfDriver($driver))->toBe(2)
            ->and($after->json('data.periods.daily.key'))->toBe('2026-10-31');
    });

    it('is lost, with the missions, when they were not done before the period ended', function () {
        // The car stands in the garage, so there are no laps.
        $driver = missionDriver();
        RaceRun::query()->where('user_id', $driver->id)->update(['stop_reason' => RaceRun::STOP_PARKED]);
        missionsOf($driver);

        Carbon::setTestNow(missionNoon()->addDays(2));
        $state = missionsOf($driver);

        expect($state->json('data.auto_collected'))->toBe([])
            ->and(coinsOfDriver($driver))->toBe(0)
            ->and(UserMission::query()->where('user_id', $driver->id)->where('period_key', '2026-10-30')->where('kind', 'daily')->whereNotNull('closed_at')->whereNull('claimed_at')->count())->toBe(2)
            ->and($state->json('data.periods.daily.key'))->toBe('2026-11-01');
    });

    it('is not offered by a game without a premium currency, and not paid by one that pays none', function () {
        missionRules(function (array $data): array {
            unset($data['premium']);

            return $data;
        });
        $driver = missionDriver();

        expect(missionsOf($driver)->json('data.periods.daily.bonus'))->toBeNull()
            ->and(claimBonus($driver)->assertStatus(422)->json('code'))->toBe('mission_bonus_unavailable');
    });
});

describe('the numbers of the missions', function () {
    it('give a free player of the best kind at most the coins the owner approved in a month, and cannot be set above the caps', function () {
        $periods = json_decode((string) file_get_contents(config('ride.content_path').'/rules/starter.json'), true, 512, JSON_THROW_ON_ERROR)['missions']['periods'];

        $month = 30 * $periods['daily']['bonus_coins'] + (int) ceil(30 / 7) * $periods['weekly']['bonus_coins'] + $periods['monthly']['bonus_coins'];

        // 2 + 8 + 25 coins: a perfect month is 60 + 40 + 25 = 125 (the weeks that touch the month count whole, a pessimistic count of 119 for 4.3 weeks).
        expect($month)->toBeLessThanOrEqual(125)->and($periods['daily']['bonus_coins'])->toBeLessThanOrEqual(5)
            ->and($periods['weekly']['bonus_coins'])->toBeLessThanOrEqual(20)->and($periods['monthly']['bonus_coins'])->toBeLessThanOrEqual(60);

        $schema = json_decode((string) file_get_contents(config('ride.content_path').'/schema/rules.schema.json'), true, 512, JSON_THROW_ON_ERROR)['properties']['missions']['properties']['periods']['properties'];

        expect([$schema['daily']['properties']['bonus_coins']['maximum'], $schema['weekly']['properties']['bonus_coins']['maximum'], $schema['monthly']['properties']['bonus_coins']['maximum']])->toBe([5, 20, 60]);
    });
});
