<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/MissionHelpers.php';

use App\Content\MissionCatalog;
use App\Content\PackValidator;
use App\Content\Rules;
use App\Content\TutorialSteps;
use App\Models\Car;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Models\UserMission;
use App\Models\Wallet;
use App\Services\DefinitionUsage;
use App\Services\Ledger;
use App\Services\MissionScale;
use Illuminate\Support\Carbon;

// The chains of main missions: they open when the tutorial is over, count everything the driver ever did (a mission is done at once when the driver has done more
// than it asks), open the next mission of the chain when one is done, wait for the level a mission asks, and pay a reward that follows the tank of the driver.
// They run on chains of their own; the content has its own tests at the end.

afterEach(fn () => Carbon::setTestNow());

beforeEach(fn () => theMissionsAre(chains: testChains()));

/** @return array<string, string> */
function words(string $pl, string $en): array
{
    return ['pl' => $pl, 'en' => $en, 'fr' => $en, 'de' => $en, 'pt-BR' => $en];
}

/** @param  array<string, mixed>  $extra */
function chainMission(string $key, string $objective, int $count, array $extra = [], array $reward = ['cash_tanks_ppm' => 500_000, 'xp_tanks_ppm' => 100_000]): array
{
    return ['key' => $key, 'title' => words("T {$key}", "T {$key}"), 'text' => words("Zrób {$key}", "Do {$key}"), 'objective' => $objective, 'count' => $count, ...$extra, 'reward' => $reward];
}

/** @return list<array<string, mixed>> */
function testChains(): array
{
    return [
        ['key' => 'road', 'title' => words('Droga', 'Road'), 'missions' => [
            chainMission('road-laps-5', 'laps', 5),
            chainMission('road-laps-20', 'laps', 20, reward: ['cash_tanks_ppm' => 1_000_000, 'items' => [['type' => 'crate', 'kind' => 'slot']]]),
            chainMission('road-level-2', 'player_level', 2, ['min_player_level' => 25]),
        ]],
        ['key' => 'garage', 'title' => words('Garaż', 'Garage'), 'missions' => [
            chainMission('garage-cars-2', 'cars_owned', 2),
            chainMission('garage-rare-1', 'rarity_owned', 1, ['param' => 'rare']),
        ]],
    ];
}

/** A driver who has left the tutorial, so that the main missions are open to them. */
function freeDriver(bool $started = true): User
{
    $driver = missionDriver($started);
    TutorialProgress::query()->create(['user_id' => $driver->id, 'step_key' => 'wreck', 'state' => TutorialProgress::STATE_SKIPPED]);

    return $driver;
}

/** @return array<string, mixed> */
function chainOf(User $driver, string $key): array
{
    return collect(missionsOf($driver)->json('data.main'))->firstWhere('key', $key);
}

describe('the main missions', function () {
    it('wait for the end of the tutorial, and open one mission of every chain when it is over', function () {
        $driver = missionDriver();

        $locked = missionsOf($driver);

        expect($locked->json('data.main_locked'))->toBe('tutorial')->and($locked->json('data.main'))->toBe([])
            ->and(UserMission::query()->where('kind', 'main')->count())->toBe(0);

        // The tutorial is over when every step is done or left out.
        $finished = User::factory()->guest()->create();
        RaceRun::factory()->create(['user_id' => $finished->id, 'cursor_ms' => Carbon::now()->getTimestampMs()]);
        foreach (app(TutorialSteps::class)->all() as $step) {
            TutorialProgress::query()->create(['user_id' => $finished->id, 'step_key' => $step['key'], 'state' => TutorialProgress::STATE_DONE]);
        }

        expect(missionsOf($finished)->json('data.main_locked'))->toBeNull()->and(count(missionsOf($finished)->json('data.main')))->toBe(2);

        $free = freeDriver();
        $main = missionsOf($free)->json('data.main');

        expect(array_column($main, 'key'))->toBe(['road', 'garage'])
            ->and($main[0]['title'])->toBeString()->and($main[0]['total'])->toBe(3)
            ->and(array_column($main[0]['missions'], 'key'))->toBe(['road-laps-5'])
            ->and($main[0]['missions'][0]['title'])->toBe('T road-laps-5')
            ->and($main[0]['missions'][0]['status'])->toBe('open')
            ->and($main[0]['missions'][0]['target'])->toBe(5)
            ->and(array_column($main[1]['missions'], 'key'))->toBe(['garage-cars-2']);
    });

    it('count everything the driver ever did, and open the next mission as soon as one is done', function () {
        $driver = freeDriver();
        missionsOf($driver);

        // Thirty laps in the next hours: more than the first two missions ask.
        Carbon::setTestNow(missionNoon()->addHours(3));
        $road = chainOf($driver, 'road');

        expect(array_column($road['missions'], 'key'))->toBe(['road-laps-5', 'road-laps-20'])
            ->and(array_column($road['missions'], 'status'))->toBe(['completed', 'completed'])
            ->and($road['done'])->toBe(2)->and($road['taken'])->toBe(0)
            // The third wants level 25, which thirty laps do not give.
            ->and($road['waiting'])->toBe(['min_player_level' => 25])
            ->and(missionsOf($driver)->json('data.claimable'))->toBeGreaterThanOrEqual(2);

        // The level opens it, and a driver of level 25 is past level two: done at once.
        (new Ledger)->grantXp($driver->id, app(Rules::class)->playerXpToReach(25));
        $road = chainOf($driver, 'road');

        expect(array_column($road['missions'], 'key'))->toBe(['road-laps-5', 'road-laps-20', 'road-level-2'])
            ->and($road['waiting'])->toBeNull()
            // Level two is where the driver is: done at once.
            ->and($road['missions'][2]['status'])->toBe('completed');
    });

    it('count the states of the game: the cars the driver has, and the parts of a rarity', function () {
        $driver = freeDriver();
        $garage = chainOf($driver, 'garage');

        expect($garage['missions'][0]['progress'])->toBe(1)->and($garage['missions'][0]['status'])->toBe('open');

        Car::factory()->create(['user_id' => $driver->id]);
        $garage = chainOf($driver, 'garage');

        // Two cars: done, and the next goal (a rare part) is open.
        expect(array_column($garage['missions'], 'key'))->toBe(['garage-cars-2', 'garage-rare-1'])->and($garage['missions'][0]['status'])->toBe('completed')
            ->and($garage['missions'][1]['status'])->toBe('open');

        Part::factory()->create(['user_id' => $driver->id, 'rarity' => 2]);
        expect(chainOf($driver, 'garage')['missions'][1]['status'])->toBe('open');

        Part::factory()->create(['user_id' => $driver->id, 'rarity' => 3]);
        expect(chainOf($driver, 'garage')['missions'][1]['status'])->toBe('completed');
    });

    it('never take a mission back: a car that was sold does not undo the mission', function () {
        $driver = freeDriver();
        $extra = Car::factory()->create(['user_id' => $driver->id]);
        expect(chainOf($driver, 'garage')['missions'][0]['status'])->toBe('completed');

        $extra->delete();

        expect(chainOf($driver, 'garage')['missions'][0]['status'])->toBe('completed');
    });
});

describe('taking a main mission', function () {
    it('pays a reward that is a part of the tank of the best car, once, and gives the crate', function () {
        $driver = freeDriver();
        missionsOf($driver);
        Carbon::setTestNow(missionNoon()->addHours(3));
        $road = chainOf($driver, 'road');
        $second = $road['missions'][1];
        $tank = app(MissionScale::class)->tankful($driver->id);

        // A tank earns 375 dollars after the fuel for the car of the factory: the second mission pays a whole tank, in whole dollars, and a crate.
        $cash = Wallet::query()->find($driver->id)->cash_cents;
        $claimed = claimMission($driver, $second['id'])->assertOk();
        $again = claimMission($driver, $second['id'])->assertOk();

        expect($second['reward']['cash_cents'])->toBe((int) (round($tank['net_cents'] / 100) * 100))
            ->and($claimed->json('claimed.cash_cents'))->toBe($second['reward']['cash_cents'])
            ->and($claimed->json('claimed.parts'))->toHaveCount(1)
            ->and($again->json('repeated'))->toBeTrue()
            ->and(Wallet::query()->find($driver->id)->cash_cents)->toBe($cash + $second['reward']['cash_cents'])
            ->and(chainOf($driver, 'road')['taken'])->toBe(1)
            // A mission that was taken is gone from the list, and the one before it is still there.
            ->and(array_column(chainOf($driver, 'road')['missions'], 'key'))->toBe(['road-laps-5']);
    });

    it('is taken together with the rest by the request that takes a kind', function () {
        $driver = freeDriver();
        missionsOf($driver);
        Carbon::setTestNow(missionNoon()->addHours(3));
        missionsOf($driver);

        $taken = $this->postJson('/api/v1/missions/claim', ['kind' => 'main'], bearerFor($driver))->assertOk();

        expect(array_column($taken->json('claimed.missions'), 'key'))->toBe(['road-laps-5', 'road-laps-20'])
            ->and(UserMission::query()->where('user_id', $driver->id)->where('kind', 'main')->whereNull('claimed_at')->whereNotNull('completed_at')->count())->toBe(0);
    });
});

describe('the away summary', function () {
    it('tells how many missions were done while the driver was away, and what the server took when a period ended', function () {
        $driver = freeDriver();
        missionsOf($driver);
        $left = Carbon::now();

        // The day of the missions ends while the driver is away: the laps are done, and the server takes them.
        Carbon::setTestNow($left->copy()->addDay());
        User::query()->whereKey($driver->id)->update(['away_from' => $left, 'away_to' => Carbon::now()]);

        $away = $this->getJson('/api/v1/away', bearerFor($driver))->assertOk();

        expect($away->json('data.missions.done'))->toBeGreaterThanOrEqual(3)
            ->and($away->json('data.missions.collected'))->toBeGreaterThanOrEqual(1)
            ->and($away->json('data.missions.cash_cents'))->toBeGreaterThan(0);
    });

    it('has nothing to tell when the missions did nothing', function () {
        $driver = missionDriver();
        RaceRun::query()->where('user_id', $driver->id)->update(['stop_reason' => RaceRun::STOP_PARKED]);
        $left = Carbon::now();
        Carbon::setTestNow($left->copy()->addHour());
        User::query()->whereKey($driver->id)->update(['away_from' => $left, 'away_to' => Carbon::now()]);

        expect($this->getJson('/api/v1/away', bearerFor($driver))->json('data.missions'))->toBeNull();
    });
});

describe('the pack', function () {
    it('keeps the main missions that drivers have', function () {
        $driver = freeDriver();
        missionsOf($driver);

        $before = ['chains' => testChains()];
        $without = ['chains' => [['key' => 'road', 'title' => words('Droga', 'Road'), 'missions' => [chainMission('road-laps-20', 'laps', 20)]], testChains()[1]]];

        expect(app(DefinitionUsage::class)->removedInUse('missions', $before, $without))->toBe(['mission road/road-laps-5 (1)'])
            ->and(app(DefinitionUsage::class)->removedInUse('missions', $before, $before))->toBe([]);
    });

    it('ships three chains of eight missions, in five languages, that cost a few tanks in all', function () {
        $catalog = MissionCatalog::fromSource(repositoryContent());
        $cash = 0;
        $xp = 0;

        expect(array_column($catalog->chains(), 'key'))->toBe(['driver', 'mechanic', 'collector']);

        foreach ($catalog->chains() as $chain) {
            expect($chain['missions'])->toHaveCount(8);

            foreach ($chain['missions'] as $mission) {
                foreach (['pl', 'en', 'fr', 'de', 'pt-BR'] as $locale) {
                    expect($mission['title'])->toHaveKey($locale)->and($mission['text'])->toHaveKey($locale);
                }

                $cash += $mission['reward']['cash_tanks_ppm'];
                $xp += $mission['reward']['xp_tanks_ppm'];
            }
        }

        // About seven tanks of cash and three of XP for the whole of the main missions, a few percent of what a driver earns on the way to level twenty.
        expect($cash / 1_000_000)->toBeBetween(5.0, 9.0)->and($xp / 1_000_000)->toBeBetween(2.0, 4.0);

        $warnings = array_filter(PackValidator::fromConfig()->validateSource(repositoryContent())->warnings(), static fn (array $issue): bool => $issue['file'] === 'rules/missions.json');

        expect($warnings)->toBe([]);
    });
});
