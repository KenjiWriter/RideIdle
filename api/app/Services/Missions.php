<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\MissionCatalog;
use App\Content\Rules;
use App\Content\TutorialSteps;
use App\Domain\Run\Rarity;
use App\Exceptions\RuleRefusedException;
use App\Models\Car;
use App\Models\CityGarage;
use App\Models\Part;
use App\Models\RaceRun;
use App\Models\TutorialProgress;
use App\Models\UserMission;
use App\Models\UserMissionPeriod;
use App\Support\I18nText;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The missions of a driver: the sets of a day, a week and a month, and what they pay.
 *
 * Like the tutorial, the missions are not told what happens. Whenever somebody looks, the server settles the cars, draws the sets that the driver has not got for
 * the period they are in (once: the set is kept, so a look again draws nothing), counts what the driver did in the window of each period out of the facts the game
 * keeps (`MissionProgress`) and keeps the highest count it has seen. A mission is done when the count reaches its goal, and it is paid when the driver takes it
 * (a button) or, if they do not, when the period has ended and the server looks again: a mission that was done is never lost. The cash goes through the ledger under a
 * key of the mission, the premium coins of a whole set under a key of the set, and both are paid in the transaction that marks them taken, under the lock of the
 * wallet, so a request that comes twice pays once.
 */
final class Missions
{
    /** The kinds of mission that a request can take at once. */
    public const KINDS = [...MissionCatalog::PERIODS, 'main'];

    public function __construct(
        private readonly Rules $rules,
        private readonly MissionPeriods $periods,
        private readonly MissionProgress $progress,
        private readonly MissionScale $scale,
        private readonly MissionDraw $draw,
        private readonly RewardPayer $payer,
        private readonly Premium $premium,
        private readonly Ledger $ledger,
        private readonly RunService $runs,
        private readonly Workshop $workshop,
        private readonly TutorialSteps $steps,
        private readonly MissionCatalog $catalog,
        private readonly DriverFacts $facts,
    ) {}

    /**
     * The missions of the driver, brought up to date.
     *
     * @return array<string, mixed>
     */
    public function view(int $userId): array
    {
        if (! $this->rules->missionsOn()) {
            return ['available' => false, 'now_ms' => RunService::nowMs()];
        }

        $state = $this->evaluate($userId);

        return $this->build($userId, $state['locked'], $state['main_locked'], $state['auto_collected']);
    }

    /**
     * Takes the reward of a mission that is done. Taking it again takes nothing (`repeated`).
     *
     * @return array{repeated: bool, mission: array<string, mixed>, result: array<string, mixed>}
     *
     * @throws RuleRefusedException `missions_unavailable`, `mission_not_found`, `mission_not_completed`
     */
    public function claim(int $userId, int $missionId): array
    {
        $this->ensureOn();
        $this->evaluate($userId);

        return DB::transaction(function () use ($userId, $missionId): array {
            $this->ledger->lockWallet($userId);

            $row = UserMission::query()->where('user_id', $userId)->whereKey($missionId)->lockForUpdate()->first() ?? throw new RuleRefusedException('mission_not_found');

            if ($row->claimed_at !== null) {
                return ['repeated' => true, 'mission' => $this->missionView($row), 'result' => $row->claim_result ?? []];
            }

            if ($row->completed_at === null) {
                throw new RuleRefusedException('mission_not_completed');
            }

            $result = $this->pay($row, auto: false);

            return ['repeated' => false, 'mission' => $this->missionView($row), 'result' => $result];
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * Takes every mission of a kind (or of all kinds) that is done and was not taken. Nothing to take is not an error.
     *
     * @return array{claimed: list<array<string, mixed>>, result: array<string, mixed>}
     *
     * @throws RuleRefusedException `missions_unavailable`
     */
    public function claimAll(int $userId, string $kind): array
    {
        $this->ensureOn();
        $this->evaluate($userId);

        return DB::transaction(function () use ($userId, $kind): array {
            $this->ledger->lockWallet($userId);

            $rows = UserMission::query()->where('user_id', $userId)->whereNotNull('completed_at')->whereNull('claimed_at')
                ->when($kind !== 'all', fn ($query) => $query->where('kind', $kind))->orderBy('id')->lockForUpdate()->get();
            $claimed = [];
            $total = ['cash_cents' => 0, 'xp' => 0, 'car_xp' => 0, 'parts' => [], 'crates' => []];

            foreach ($rows as $row) {
                $result = $this->pay($row, auto: false);
                $claimed[] = $this->missionView($row);
                $total = [
                    'cash_cents' => $total['cash_cents'] + $result['cash_cents'],
                    'xp' => $total['xp'] + $result['xp'],
                    'car_xp' => $total['car_xp'] + $result['car_xp'],
                    'parts' => [...$total['parts'], ...$result['parts']],
                    'crates' => [...$total['crates'], ...$result['crates']],
                ];
            }

            return ['claimed' => $claimed, 'result' => $total];
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * Takes the premium coins for having done every mission of the set of the period the driver is in. Taking them again takes nothing (`repeated`).
     *
     * @return array{repeated: bool, coins: int}
     *
     * @throws RuleRefusedException `missions_unavailable`, `mission_bonus_unavailable` (the set pays none), `mission_bonus_not_ready`
     */
    public function claimBonus(int $userId, string $kind): array
    {
        $this->ensureOn();
        $this->evaluate($userId);

        return DB::transaction(function () use ($userId, $kind): array {
            $this->ledger->lockWallet($userId);

            $period = $this->periods->at($kind, Carbon::now());
            $row = UserMissionPeriod::query()->where('user_id', $userId)->where('kind', $kind)->where('period_key', $period['key'])->lockForUpdate()->first();

            if ($row === null || $row->bonus_coins <= 0) {
                throw new RuleRefusedException('mission_bonus_unavailable');
            }

            if ($row->bonus_claimed_at !== null) {
                return ['repeated' => true, 'coins' => $row->bonus_coins];
            }

            if ($row->completed_at === null) {
                throw new RuleRefusedException('mission_bonus_not_ready');
            }

            $this->payBonus($row, auto: false);

            return ['repeated' => false, 'coins' => $row->bonus_coins];
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * Brings the missions of the driver up to date: the cars are settled and the jobs of the workshop that are due are carried out, so that everything that was
     * driven or done while the driver was away is on the books; then, in one transaction under the lock of the wallet, the periods that have ended are closed
     * (what was done is counted for the last time, and what is done and was not taken is taken), the sets of the periods the driver is in are drawn if they are
     * not yet, and the progress of every mission that is not done is counted.
     *
     * @return array{locked: string|null, main_locked: string|null, auto_collected: list<array<string, mixed>>} `locked` says why there are no sets and `main_locked` why there
     *                                                                                                          are no main missions: `tutorial` (not far enough yet)
     */
    public function evaluate(int $userId): array
    {
        $this->workshop->advance($userId);

        foreach (RaceRun::query()->where('user_id', $userId)->pluck('id') as $runId) {
            $this->runs->settle((int) $runId);
        }

        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId): array {
            $this->ledger->lockWallet($userId);

            $now = Carbon::now();
            $collected = $this->closeFinished($userId, $now->getTimestampMs());
            $locked = $this->lockedBy($userId);

            if ($locked === null) {
                foreach (MissionCatalog::PERIODS as $kind) {
                    $period = $this->periods->at($kind, $now);

                    $this->drawSet($userId, $kind, $period);
                    $this->bringUpToDate($userId, $kind, $period['key']);
                }
            }

            $mainLocked = $this->mainLockedBy($userId);

            if ($mainLocked === null) {
                foreach ($this->catalog->chains() as $chain) {
                    $this->advanceChain($userId, $chain);
                }
            }

            return ['locked' => $locked, 'main_locked' => $mainLocked, 'auto_collected' => $collected];
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * What the missions of the driver did while they were away, from the moment the break began: how many were noticed done, and what the server took for the driver
     * when a period ended (the cash, the XP and the coins of a whole set). Null when there is nothing to tell. The caller has brought the missions up to date.
     *
     * @return array{done: int, collected: int, cash_cents: int, xp: int, coins: int}|null
     */
    public function awayReport(int $userId, CarbonInterface $from): ?array
    {
        if (! $this->rules->missionsOn()) {
            return null;
        }

        $done = UserMission::query()->where('user_id', $userId)->where('completed_at', '>=', $from)->count();
        $taken = UserMission::query()->where('user_id', $userId)->where('auto_claimed', true)->where('claimed_at', '>=', $from)->get(['claim_result']);
        $coins = (int) UserMissionPeriod::query()->where('user_id', $userId)->where('bonus_auto_claimed', true)->where('bonus_claimed_at', '>=', $from)->sum('bonus_coins');
        $report = [
            'done' => $done,
            'collected' => $taken->count(),
            'cash_cents' => (int) $taken->sum(fn (UserMission $row): int => (int) ($row->claim_result['cash_cents'] ?? 0)),
            'xp' => (int) $taken->sum(fn (UserMission $row): int => (int) ($row->claim_result['xp'] ?? 0)),
            'coins' => $coins,
        ];

        return $done === 0 && $report['collected'] === 0 && $coins === 0 ? null : $report;
    }

    /** Why a driver has no main missions yet: the tutorial is not over. A driver who left it has them. */
    private function mainLockedBy(int $userId): ?string
    {
        $rows = TutorialProgress::query()->where('user_id', $userId)->get();

        if ($rows->contains('state', TutorialProgress::STATE_SKIPPED)) {
            return null;
        }

        return $rows->whereIn('state', [TutorialProgress::STATE_DONE, TutorialProgress::STATE_DECLINED])->count() >= $this->steps->count() ? null : 'tutorial';
    }

    /**
     * Keeps a chain of main missions up to date: counts the mission that is open, and opens the next one when it is done and the level of the driver allows (a
     * driver who has done more than the first missions asks gets several done at once).
     *
     * @param  array{key: string, missions: list<array<string, mixed>>}  $chain
     */
    private function advanceChain(int $userId, array $chain): void
    {
        for ($guard = 0; $guard < count($chain['missions']); $guard++) {
            $rows = UserMission::query()->where('user_id', $userId)->where('kind', UserMission::KIND_MAIN)->where('period_key', $chain['key'])->orderBy('slot')->get();

            $this->count($rows);

            if ($rows->isNotEmpty() && $rows->last()->completed_at === null) {
                return;
            }

            $next = $chain['missions'][$rows->count()] ?? null;
            $tankful = $next === null ? null : $this->scale->tankful($userId);

            if ($next === null || $tankful === null || $this->rules->playerLevelAt($this->ledger->xp($userId)) < ($next['min_player_level'] ?? 1)) {
                return;
            }

            $now = now();

            UserMission::query()->insertOrIgnore([
                'user_id' => $userId,
                'kind' => UserMission::KIND_MAIN,
                'period_key' => $chain['key'],
                'slot' => $rows->count() + 1,
                'mission_key' => $next['key'],
                'objective' => $next['objective'],
                'params' => json_encode(['param' => $next['param'] ?? null], JSON_THROW_ON_ERROR),
                'target' => $next['count'],
                'progress' => 0,
                'reward' => json_encode($this->draw->chainReward($next['reward'], $tankful), JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** What the driver has reached of a main mission, counted over all the time, or a state of the game (a level, the cars they have). */
    private function measureMain(UserMission $row): int
    {
        $param = (string) ($row->params['param'] ?? '');

        return match ($row->objective) {
            'player_level' => $this->rules->playerLevelAt($this->ledger->xp($row->user_id)),
            'car_level' => (int) Car::query()->where('user_id', $row->user_id)->max('level'),
            'cars_owned' => Car::query()->where('user_id', $row->user_id)->count(),
            'garages_owned' => CityGarage::query()->where('user_id', $row->user_id)->count(),
            'rarity_owned' => Part::query()->where('user_id', $row->user_id)->where('rarity', '>=', Rarity::fromKey($param)->value)->count(),
            'tires_mounted' => $this->facts->tiresMounted($row->user_id, $param) ? 1 : 0,
            default => $this->progress->measure($row->user_id, $row->objective, 0, PHP_INT_MAX),
        };
    }

    /**
     * The chains of main missions for the screen: for each, the missions that are open or done and not yet taken, how many are done and taken, and, when the next one
     * waits for a level, which.
     *
     * @return list<array<string, mixed>>
     */
    private function mainView(int $userId): array
    {
        $level = $this->rules->playerLevelAt($this->ledger->xp($userId));
        $chains = [];

        foreach ($this->catalog->chains() as $chain) {
            $rows = UserMission::query()->where('user_id', $userId)->where('kind', UserMission::KIND_MAIN)->where('period_key', $chain['key'])->orderBy('slot')->get();
            $next = $chain['missions'][$rows->count()] ?? null;
            $open = $rows->isEmpty() || $rows->last()->completed_at !== null;

            $chains[] = [
                'key' => $chain['key'],
                'title' => I18nText::from($chain['title'])->for(),
                'total' => count($chain['missions']),
                'done' => $rows->filter(fn (UserMission $row): bool => $row->completed_at !== null)->count(),
                'taken' => $rows->filter(fn (UserMission $row): bool => $row->claimed_at !== null)->count(),
                'missions' => $rows->filter(fn (UserMission $row): bool => $row->claimed_at === null)->values()->map(fn (UserMission $row): array => $this->missionView($row))->all(),
                'waiting' => $open && $next !== null && $level < ($next['min_player_level'] ?? 1) ? ['min_player_level' => (int) $next['min_player_level']] : null,
            ];
        }

        return $chains;
    }

    private function ensureOn(): void
    {
        if (! $this->rules->missionsOn()) {
            throw new RuleRefusedException('missions_unavailable');
        }
    }

    /** Why a driver has no sets yet: they have not got far enough in the tutorial. A driver who left the tutorial has them. */
    private function lockedBy(int $userId): ?string
    {
        $step = $this->rules->missions()['unlock_after_step'] ?? '';

        if ($this->steps->find($step) === null) {
            return null;
        }

        $rows = TutorialProgress::query()->where('user_id', $userId)->get()->keyBy('step_key');

        if ($rows->contains('state', TutorialProgress::STATE_SKIPPED)) {
            return null;
        }

        return in_array($rows->get($step)?->state, [TutorialProgress::STATE_DONE, TutorialProgress::STATE_DECLINED], true) ? null : 'tutorial';
    }

    /**
     * Draws the set of the period if the driver has none: one row for the set and one for each of its missions. A driver who has no car that can drive has no
     * set yet, and gets one the first time they do.
     *
     * @param  array{key: string, from_ms: int, to_ms: int}  $period
     */
    private function drawSet(int $userId, string $kind, array $period): void
    {
        if (UserMissionPeriod::query()->where('user_id', $userId)->where('kind', $kind)->where('period_key', $period['key'])->exists()) {
            return;
        }

        $tankful = $this->scale->tankful($userId);

        if ($tankful === null) {
            return;
        }

        $set = $this->draw->set($userId, $kind, $period['key'], $this->rules->playerLevelAt($this->ledger->xp($userId)), $tankful);

        if ($set === []) {
            return;
        }

        $now = now();
        $coins = $this->rules->premiumCurrency() === null ? 0 : $this->rules->missions()['periods'][$kind]['bonus_coins'];

        UserMissionPeriod::query()->insertOrIgnore([
            'user_id' => $userId,
            'kind' => $kind,
            'period_key' => $period['key'],
            'missions' => count($set),
            'bonus_coins' => $coins,
            'window_from_ms' => $period['from_ms'],
            'window_to_ms' => $period['to_ms'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        UserMission::query()->insertOrIgnore(array_map(static fn (array $mission): array => [
            'user_id' => $userId,
            'kind' => $kind,
            'period_key' => $period['key'],
            'slot' => $mission['slot'],
            'mission_key' => $mission['key'],
            'objective' => $mission['objective'],
            'params' => null,
            'target' => $mission['target'],
            'progress' => 0,
            'reward' => json_encode($mission['reward'], JSON_THROW_ON_ERROR),
            'window_from_ms' => $period['from_ms'],
            'window_to_ms' => $period['to_ms'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $set));
    }

    /** Counts the missions of a set that are not done, and notes the set as done when every mission of it is. */
    private function bringUpToDate(int $userId, string $kind, string $periodKey): void
    {
        $rows = UserMission::query()->where('user_id', $userId)->where('kind', $kind)->where('period_key', $periodKey)->orderBy('slot')->get();

        $this->count($rows);
        $this->noteSetDone($userId, $kind, $periodKey, $rows);
    }

    /**
     * Raises the progress of the missions that are not done to what the driver did in their window; a mission whose goal is reached is done. The same goal in the
     * same window is counted once.
     *
     * @param  Collection<int, UserMission>  $rows
     */
    private function count(Collection $rows): void
    {
        $counted = [];

        foreach ($rows as $row) {
            if ($row->completed_at !== null) {
                continue;
            }

            $key = "{$row->objective}|{$row->window_from_ms}|{$row->window_to_ms}";

            if ($row->kind === UserMission::KIND_MAIN) {
                $done = $this->measureMain($row);
            } else {
                $done = $counted[$key] ??= $this->progress->measure($row->user_id, $row->objective, (int) $row->window_from_ms, (int) $row->window_to_ms);
            }

            $progress = max($row->progress, $done);

            if ($progress !== $row->progress || $progress >= $row->target) {
                $row->forceFill(['progress' => $progress, 'completed_at' => $progress >= $row->target ? now() : null])->save();
            }
        }
    }

    /** @param  Collection<int, UserMission>  $rows */
    private function noteSetDone(int $userId, string $kind, string $periodKey, Collection $rows): void
    {
        if ($rows->isEmpty() || $rows->contains(fn (UserMission $row): bool => $row->completed_at === null)) {
            return;
        }

        UserMissionPeriod::query()->where('user_id', $userId)->where('kind', $kind)->where('period_key', $periodKey)->whereNull('completed_at')->update(['completed_at' => now()]);
    }

    /**
     * Closes the periods that have ended: what the driver did in them is counted for the last time (laps that were settled late belong to the window they were
     * driven in), what is done and was not taken is taken for them, and so is the bonus of a set that was done whole.
     *
     * @return list<array<string, mixed>> what was taken
     */
    private function closeFinished(int $userId, int $nowMs): array
    {
        $collected = [];

        $rows = UserMission::query()->where('user_id', $userId)->whereNull('closed_at')->whereNotNull('window_to_ms')->where('window_to_ms', '<=', $nowMs)->orderBy('id')->get();

        foreach ($rows->groupBy(static fn (UserMission $row): string => "{$row->kind}|{$row->period_key}") as $set) {
            $this->count($set);
            $first = $set->first();
            $this->noteSetDone($userId, $first->kind, $first->period_key, $set);

            foreach ($set as $row) {
                if ($row->completed_at !== null && $row->claimed_at === null) {
                    $result = $this->pay($row, auto: true);
                    $collected[] = ['type' => 'mission', 'kind' => $row->kind, 'period_key' => $row->period_key, 'mission' => $this->missionView($row), 'result' => $result];
                }

                $row->forceFill(['closed_at' => now()])->save();
            }
        }

        $periods = UserMissionPeriod::query()->where('user_id', $userId)->whereNull('closed_at')->where('window_to_ms', '<=', $nowMs)->orderBy('id')->get();

        foreach ($periods as $period) {
            if ($period->completed_at !== null && $period->bonus_coins > 0 && $period->bonus_claimed_at === null) {
                $this->payBonus($period, auto: true);
                $collected[] = ['type' => 'bonus', 'kind' => $period->kind, 'period_key' => $period->period_key, 'coins' => $period->bonus_coins];
            }

            $period->forceFill(['closed_at' => now()])->save();
        }

        return $collected;
    }

    /**
     * Pays a mission and notes it as taken. The caller holds the lock of the wallet and has seen that it is done and not taken.
     *
     * @return array{cash_cents: int, xp: int, car_xp: int, parts: list<int>, crates: list<string>}
     */
    private function pay(UserMission $row, bool $auto): array
    {
        $result = $this->payer->payMission($row->user_id, $row->reward, "mission:{$row->id}", "mission:{$row->id}");

        $row->forceFill(['claimed_at' => now(), 'auto_claimed' => $auto, 'claim_result' => $result])->save();

        return $result;
    }

    private function payBonus(UserMissionPeriod $period, bool $auto): void
    {
        $this->premium->grant($period->user_id, $period->bonus_coins, 'mission_bonus', "missionbonus:{$period->user_id}:{$period->kind}:{$period->period_key}");

        $period->forceFill(['bonus_claimed_at' => now(), 'bonus_auto_claimed' => $auto])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $collected
     * @return array<string, mixed>
     */
    private function build(int $userId, ?string $locked, ?string $mainLocked, array $collected): array
    {
        $now = Carbon::now();
        $periods = [];

        foreach (MissionCatalog::PERIODS as $kind) {
            $window = $this->periods->at($kind, $now);
            $set = UserMissionPeriod::query()->where('user_id', $userId)->where('kind', $kind)->where('period_key', $window['key'])->first();
            $rows = UserMission::query()->where('user_id', $userId)->where('kind', $kind)->where('period_key', $window['key'])->orderBy('slot')->get();

            $periods[$kind] = [
                'key' => $window['key'],
                'from_ms' => $window['from_ms'],
                'to_ms' => $window['to_ms'],
                'resets_in_ms' => max(0, $window['to_ms'] - $now->getTimestampMs()),
                'drawn' => $set !== null,
                'missions' => $rows->map(fn (UserMission $row): array => $this->missionView($row))->all(),
                'done' => $rows->filter(fn (UserMission $row): bool => $row->completed_at !== null)->count(),
                'total' => $rows->count(),
                'bonus' => $set === null || $set->bonus_coins <= 0 ? null : ['coins' => $set->bonus_coins, 'state' => $set->bonusState()],
            ];
        }

        $takeable = UserMission::query()->where('user_id', $userId)->whereNotNull('completed_at')->whereNull('claimed_at')->count()
            + UserMissionPeriod::query()->where('user_id', $userId)->whereNotNull('completed_at')->whereNull('bonus_claimed_at')->where('bonus_coins', '>', 0)->count();

        return [
            'available' => true,
            'now_ms' => $now->getTimestampMs(),
            'locked' => $locked,
            'main_locked' => $mainLocked,
            'claimable' => $takeable,
            'periods' => $periods,
            'main' => $mainLocked === null ? $this->mainView($userId) : [],
            'auto_collected' => $collected,
        ];
    }

    /** @return array<string, mixed> */
    private function missionView(UserMission $row): array
    {
        $main = $row->kind === UserMission::KIND_MAIN ? $this->catalog->chainMission($row->period_key, $row->mission_key) : null;

        return [
            'id' => $row->id,
            'kind' => $row->kind,
            'slot' => $row->slot,
            'key' => $row->mission_key,
            ...($row->kind === UserMission::KIND_MAIN ? [
                'chain' => $row->period_key,
                'title' => $main === null ? $row->mission_key : I18nText::from($main['title'])->for(),
                'text' => $main === null ? '' : I18nText::from($main['text'])->for(),
                'param' => $row->params['param'] ?? null,
            ] : []),
            'objective' => $row->objective,
            'target' => $row->target,
            'progress' => min($row->progress, $row->target),
            'status' => $row->status(),
            'reward' => $row->reward,
            'auto_claimed' => $row->auto_claimed,
            'claimed_at_ms' => $row->claimed_at?->getTimestampMs(),
        ];
    }
}
