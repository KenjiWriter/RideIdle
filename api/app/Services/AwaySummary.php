<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Domain\Settlement\EventType;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\Travel;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Support\I18nText;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What happened to the whole account while the driver was away: what each car earned, which jobs of the workshop were done, which journeys ended (and what the
 * police made of a boosted one), and which cars stand and wait for the driver. The card of the garage tells the same of the car that is played; this is the rest.
 *
 * The break is the one the middleware noted (`note`): from the visit before it to the visit that ended it, and it stays until the driver says they have seen it
 * (`dismiss`). It is only as exact as the visits are (one every ten minutes of play), so the times are about right, and the earnings of a settlement that
 * began before the break or ended after it are shared by the time that falls inside.
 */
final class AwaySummary
{
    /** A break shorter than this is not worth a card. */
    public const MIN_AWAY_MINUTES = 15;

    /** What is told reaches back this many days at most. */
    public const MAX_DAYS = 7;

    public function __construct(
        private readonly Workshop $workshop,
        private readonly RunService $runs,
        private readonly Catalog $catalog,
        private readonly Rules $rules,
    ) {}

    /**
     * The columns of the user that note a break that has just ended: the break starts when the previous visit began (or where an earlier break the driver did not
     * look at starts), and ends now. Nothing for a pause that is too short.
     *
     * @return array<string, CarbonInterface>
     */
    public function note(User $user, ?CarbonInterface $previousVisit, CarbonInterface $now): array
    {
        if ($previousVisit === null || $previousVisit->diffInMinutes($now, true) < self::MIN_AWAY_MINUTES) {
            return [];
        }

        return ['away_from' => $user->away_from ?? $previousVisit, 'away_to' => $now];
    }

    /** The driver has seen the summary: the break is over. */
    public function dismiss(User $user): void
    {
        User::query()->whereKey($user->getKey())->update(['away_from' => null, 'away_to' => null]);
        $user->setAttribute('away_from', null);
        $user->setAttribute('away_to', null);
    }

    /**
     * The summary of the break that waits for the driver, or `pending: false` when there is none. What is due is carried out first and every car is settled, so the
     * earnings are current.
     *
     * @return array{pending: bool, from_ms?: int, to_ms?: int, away_minutes?: int, earned_cents?: int, bonus_cents?: int, auto_refills?: int, auto_refill_charges?: int, cars?: list<array<string, mixed>>, jobs?: list<array<string, mixed>>, journeys?: list<array<string, mixed>>}
     */
    public function summary(User $user): array
    {
        if ($user->away_from === null || $user->away_to === null) {
            return ['pending' => false];
        }

        $userId = (int) $user->getKey();
        $this->workshop->advance($userId);

        $runs = RaceRun::query()->where('user_id', $userId)->with('car')->orderBy('id')->get();

        foreach ($runs as $run) {
            $this->runs->settle((int) $run->id);
        }

        $runs = RaceRun::query()->where('user_id', $userId)->with('car')->orderBy('id')->get();
        $to = Carbon::instance($user->away_to);
        $from = Carbon::instance($user->away_from)->max($to->copy()->subDays(self::MAX_DAYS));
        $fromMs = $from->getTimestampMs();
        $toMs = $to->getTimestampMs();
        $played = $user->selected_car_id;
        $cars = $this->cars($runs, $fromMs, $toMs, $played);

        return [
            'pending' => true,
            'from_ms' => $fromMs,
            'to_ms' => $toMs,
            'away_minutes' => (int) $from->diffInMinutes($to, true),
            'earned_cents' => array_sum(array_column($cars, 'earned_cents')),
            // What the VIP account added to it, and how many times the tank was filled for the driver (and how many charges that took: the pass fills it for nothing).
            'bonus_cents' => array_sum(array_column($cars, 'bonus_cents')),
            'auto_refills' => array_sum(array_column($cars, 'refills')),
            'auto_refill_charges' => array_sum(array_column($cars, 'refill_charges')),
            'cars' => $cars,
            'jobs' => $this->jobs($userId, $fromMs, $toMs),
            'journeys' => $this->journeys($userId, $fromMs, $toMs),
        ];
    }

    /**
     * What each car earned in the break, and whether it stands and waits. A car that earned nothing and does not wait for anything is left out.
     *
     * @param  Collection<int, RaceRun>  $runs
     * @return list<array{id: int|null, name: string, played: bool, earned_cents: int, bonus_cents: int, laps: int, refills: int, refill_charges: int, waiting: string|null}>
     */
    private function cars($runs, int $fromMs, int $toMs, ?int $played): array
    {
        $settlements = Settlement::query()
            ->whereIn('race_run_id', $runs->modelKeys())
            ->where('cursor_to_ms', '>', $fromMs)
            ->where('cursor_from_ms', '<', $toMs)
            ->get(['race_run_id', 'cursor_from_ms', 'cursor_to_ms', 'laps', 'cash_cents', 'bonus_cash_cents'])
            ->groupBy('race_run_id');
        $refills = $this->refills($runs->modelKeys(), $fromMs, $toMs);
        $cars = [];

        foreach ($runs as $run) {
            $earned = 0;
            $bonus = 0;
            $laps = 0;

            foreach ($settlements->get($run->id, collect()) as $settlement) {
                $span = max(1, $settlement->cursor_to_ms - $settlement->cursor_from_ms);
                $inside = min($toMs, $settlement->cursor_to_ms) - max($fromMs, $settlement->cursor_from_ms);

                if ($inside > 0) {
                    $earned += (int) round($settlement->cash_cents * $inside / $span);
                    $bonus += (int) round($settlement->bonus_cash_cents * $inside / $span);
                    $laps += (int) round($settlement->laps * $inside / $span);
                }
            }

            // The car waits for the driver when it has no fuel or has broken down; the others drive, or stand for a reason of their own (the workshop, the road).
            $waiting = in_array($run->stop_reason, [EventType::FuelEmpty->value, EventType::Breakdown->value], true) ? $run->stop_reason : null;

            if ($earned === 0 && $waiting === null) {
                continue;
            }

            $vehicle = $run->car === null ? null : $this->catalog->vehicle($run->car->vehicle_slug);

            $cars[] = [
                'id' => $run->car_id,
                'name' => $vehicle === null ? (string) ($run->car?->vehicle_slug ?? '') : I18nText::from($vehicle['name'])->for(),
                'played' => $run->car_id !== null && $run->car_id === $played,
                'earned_cents' => $earned,
                'bonus_cents' => $bonus,
                'laps' => $laps,
                'refills' => count($refills[$run->id] ?? []),
                'refill_charges' => count(array_filter($refills[$run->id] ?? [], static fn (bool $free): bool => ! $free)),
                'waiting' => $waiting,
            ];
        }

        usort($cars, static fn (array $a, array $b): int => $b['earned_cents'] <=> $a['earned_cents']);

        return $cars;
    }

    /**
     * The times the tank was filled for the driver in the break, by run: for each, whether it was free (the pass paid for it).
     *
     * @param  list<int|string>  $runIds
     * @return array<int, list<bool>>
     */
    private function refills(array $runIds, int $fromMs, int $toMs): array
    {
        $found = [];

        $rows = DB::table('settlement_events')
            ->join('settlements', 'settlements.id', '=', 'settlement_events.settlement_id')
            ->whereIn('settlements.race_run_id', $runIds)
            ->where('settlement_events.type', EventType::AutoRefill->value)
            ->whereBetween('settlement_events.at_ms', [$fromMs, $toMs])
            ->get(['settlements.race_run_id as run_id', 'settlement_events.payload']);

        foreach ($rows as $row) {
            $found[(int) $row->run_id][] = (int) (json_decode((string) $row->payload, true)['free'] ?? 0) === 1;
        }

        return $found;
    }

    /**
     * The jobs of the workshop that were done in the break.
     *
     * @return list<array{kind: string, part: string|null, finished_ms: int}>
     */
    private function jobs(int $userId, int $fromMs, int $toMs): array
    {
        return WorkshopJob::query()
            ->where('user_id', $userId)
            ->where('status', WorkshopJob::STATUS_DONE)
            ->where('finish_ms', '>=', $fromMs)
            ->where('finish_ms', '<=', $toMs)
            ->with('part')
            ->orderBy('finish_ms')
            ->get()
            ->map(function (WorkshopJob $job): array {
                $definition = $job->part === null ? null : $this->catalog->part($job->part->part_slug);

                return ['kind' => $job->kind, 'part' => $definition === null ? null : I18nText::from($definition['name'])->for(), 'finished_ms' => (int) $job->finish_ms];
            })
            ->all();
    }

    /**
     * The journeys that ended in the break, with what the police made of a boosted one.
     *
     * @return list<array{to: string, arrived_ms: int, boosted: bool, police: array{outcome: string, paid_cents: int}|null}>
     */
    private function journeys(int $userId, int $fromMs, int $toMs): array
    {
        return Travel::query()
            ->where('user_id', $userId)
            ->where('status', Travel::STATUS_ARRIVED)
            ->where('arrive_ms', '>=', $fromMs)
            ->where('arrive_ms', '<=', $toMs)
            ->orderBy('arrive_ms')
            ->get()
            ->map(fn (Travel $journey): array => [
                'to' => $this->rules->cityName($journey->to_region),
                'arrived_ms' => (int) $journey->arrive_ms,
                'boosted' => (bool) $journey->boosted,
                'police' => $journey->police_outcome === null || $journey->police_outcome === 'waived' ? null : ['outcome' => $journey->police_outcome, 'paid_cents' => (int) $journey->police_paid_cents],
            ])
            ->all();
    }
}
