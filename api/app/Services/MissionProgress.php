<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Models\CrateOpening;
use App\Models\LedgerEntry;
use App\Models\MergeAttempt;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\SettlementEvent;
use App\Models\Travel;
use App\Models\WorkshopJob;
use Illuminate\Support\Carbon;

/**
 * What a driver did in a window of time, counted out of the facts the game keeps anyway: the laps and what they paid (the settlements of their runs, and the
 * `lap_batch` events inside them, which say when), the jobs of the workshop that were done, the journeys that ended, the crates that were opened, and what was
 * paid for fuel, parts and cars (the ledger). Nothing is counted as it happens: asking twice gives the same answer, a lap that was settled late is in the
 * window it was driven in, and nothing is counted twice, because every fact is one row.
 *
 * The window is from `$fromMs` to `$toMs`, the start included and the end not. What the missions themselves paid is not counted, nor are the crates they opened,
 * so a reward cannot be a step towards the next one.
 */
final class MissionProgress
{
    /** The reason of the crates that missions opened: they have a count of their own for the guarantees, and are not the crates of the shop. */
    public const CRATE_STREAM_PREFIX = 'm:';

    public function __construct(private readonly Rules $rules) {}

    /** Whether the goal is one that this class can count over a window. */
    public function counts(string $objective): bool
    {
        return in_array($objective, ['laps', 'cash_earned', 'wet_laps', 'fuel_spent', 'upgrade', 'mount', 'service', 'crate', 'parts_bought', 'journey', 'car_bought', 'repair', 'merge'], true);
    }

    /** How much of a goal the driver reached in the window: laps, cents or times. */
    public function measure(int $userId, string $objective, int $fromMs, int $toMs): int
    {
        return match ($objective) {
            'laps' => $this->laps($userId, $fromMs, $toMs, wetOnly: false)['laps'],
            'wet_laps' => $this->laps($userId, $fromMs, $toMs, wetOnly: true)['laps'],
            'cash_earned' => $this->laps($userId, $fromMs, $toMs, wetOnly: false)['cash'],
            'fuel_spent' => $this->spent($userId, ['refuel'], $fromMs, $toMs, sum: true),
            'parts_bought' => $this->spent($userId, ['purchase'], $fromMs, $toMs),
            'car_bought' => $this->spent($userId, ['exchange', 'dealer'], $fromMs, $toMs),
            'repair' => $this->spent($userId, ['repair'], $fromMs, $toMs),
            'upgrade' => $this->jobs($userId, WorkshopJob::KIND_UPGRADE, $fromMs, $toMs),
            'mount' => $this->jobs($userId, WorkshopJob::KIND_MOUNT, $fromMs, $toMs),
            'service' => $this->jobs($userId, WorkshopJob::KIND_SERVICE, $fromMs, $toMs),
            'merge' => MergeAttempt::query()->where('user_id', $userId)->where('success', true)->where('finished_ms', '>=', $fromMs)->where('finished_ms', '<', $toMs)->count(),
            'journey' => Travel::query()->where('user_id', $userId)->where('status', Travel::STATUS_ARRIVED)->where('arrive_ms', '>=', $fromMs)->where('arrive_ms', '<', $toMs)->count(),
            'crate' => CrateOpening::query()->where('user_id', $userId)->where('pity_group', 'not like', self::CRATE_STREAM_PREFIX.'%')
                ->where('created_at', '>=', $this->at($fromMs))->where('created_at', '<', $this->at($toMs))->count(),
            default => 0,
        };
    }

    /**
     * The laps of every car of the driver whose batches ended in the window, and the cash they paid. A settlement that lies wholly inside the window is added up
     * from its row; one that the window cuts is taken apart into its batches, which have the time each ended at.
     *
     * @return array{laps: int, cash: int}
     */
    private function laps(int $userId, int $fromMs, int $toMs, bool $wetOnly): array
    {
        $runs = RaceRun::withoutGlobalScopes()->where('user_id', $userId)->pluck('id');
        $laps = 0;
        $cash = 0;
        $cut = [];

        $settlements = Settlement::query()->whereIn('race_run_id', $runs)
            ->where('cursor_to_ms', '>=', $fromMs)->where('cursor_from_ms', '<', $toMs)
            ->get(['id', 'cursor_from_ms', 'cursor_to_ms', 'laps', 'cash_cents']);

        foreach ($settlements as $settlement) {
            // The batches of a settlement end after its cursor started and not after it ended.
            if (! $wetOnly && $settlement->cursor_from_ms >= $fromMs && $settlement->cursor_to_ms < $toMs) {
                $laps += $settlement->laps;
                $cash += $settlement->cash_cents;
            } else {
                $cut[] = $settlement->id;
            }
        }

        if ($cut === []) {
            return ['laps' => $laps, 'cash' => $cash];
        }

        $batches = SettlementEvent::query()->whereIn('settlement_id', $cut)->where('type', 'lap_batch')->where('at_ms', '>=', $fromMs)->where('at_ms', '<', $toMs)->get(['payload']);

        foreach ($batches as $batch) {
            $payload = $batch->payload;

            if ($wetOnly && ($this->rules->weather()[$payload['weather'] ?? '']['ground'] ?? 'dry') !== 'wet') {
                continue;
            }

            $laps += (int) ($payload['laps'] ?? 0);
            $cash += (int) ($payload['cash_cents'] ?? 0);
        }

        return ['laps' => $laps, 'cash' => $cash];
    }

    /**
     * What the driver paid in cash for the reasons in the window: how many payments, or, with `$sum`, how many cents.
     *
     * @param  list<string>  $reasons
     */
    private function spent(int $userId, array $reasons, int $fromMs, int $toMs, bool $sum = false): int
    {
        $entries = LedgerEntry::query()->where('user_id', $userId)->where('currency', 'cash')->whereIn('reason', $reasons)->where('delta', '<', 0)
            ->where('created_at', '>=', $this->at($fromMs))->where('created_at', '<', $this->at($toMs));

        return $sum ? -(int) $entries->sum('delta') : $entries->count();
    }

    /** The jobs of the workshop of a kind that were done in the window, but not the ones that the tutorial arranged. */
    private function jobs(int $userId, string $kind, int $fromMs, int $toMs): int
    {
        return WorkshopJob::query()->where('user_id', $userId)->where('kind', $kind)->where('status', WorkshopJob::STATUS_DONE)->where('scripted', false)
            ->where('finish_ms', '>=', $fromMs)->where('finish_ms', '<', $toMs)->count();
    }

    private function at(int $ms): Carbon
    {
        return Carbon::createFromTimestampMs(min($ms, 253_402_300_799_000));
    }
}
