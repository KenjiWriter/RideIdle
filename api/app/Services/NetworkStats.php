<?php

declare(strict_types=1);

namespace App\Services;

use App\Games\CurrentGame;
use App\Models\Game;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserVisit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What each game of the installation has in it, for the home of the panel: the accounts, the ones that came back, what was bought. It is asked of the tables of every game
 * at once (grouped by the game of the account), not game by game, so a network of many games is still a handful of queries. The only thing that has to be asked game by
 * game is the pack that is live, which belongs to the game in play.
 */
final class NetworkStats
{
    /** The days that count as the recent past in the numbers of the home. */
    public const WEEK_DAYS = 7;

    public const MONTH_DAYS = 30;

    public function __construct(private readonly CurrentGame $current, private readonly ContentPacks $packs) {}

    /**
     * The numbers of some games, by the id of the game (a game with nothing in it has zeros).
     *
     * @param  Collection<int, Game>  $games
     * @return array<int, array{accounts: int, guests: int, secured: int, new_week: int, active_day: int, active_week: int, revenue_total: array<string, int>, revenue_month: array<string, int>, pack: string|null}>
     */
    public function for(Collection $games, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $ids = $games->pluck('id')->all();
        $week = $now->subDays(self::WEEK_DAYS);
        $day = $now->subDay();
        $month = $now->subDays(self::MONTH_DAYS);

        $stats = [];

        foreach ($ids as $id) {
            $stats[$id] = ['accounts' => 0, 'guests' => 0, 'secured' => 0, 'new_week' => 0, 'active_day' => 0, 'active_week' => 0, 'revenue_total' => [], 'revenue_month' => [], 'pack' => null];
        }

        if ($ids === []) {
            return $stats;
        }

        $accounts = User::query()->withoutGlobalScopes()->whereIn('game_id', $ids)
            ->selectRaw('game_id, count(*) as accounts, sum(case when claimed_at is null then 1 else 0 end) as guests, sum(case when created_at >= ? then 1 else 0 end) as new_week', [$week])
            ->groupBy('game_id')->get();

        foreach ($accounts as $row) {
            $stats[(int) $row->game_id]['accounts'] = (int) $row->accounts;
            $stats[(int) $row->game_id]['guests'] = (int) $row->guests;
            $stats[(int) $row->game_id]['secured'] = (int) $row->accounts - (int) $row->guests;
            $stats[(int) $row->game_id]['new_week'] = (int) $row->new_week;
        }

        // Who came back: the accounts that have a visit in the last week, and the ones of them that have one in the last day.
        $visits = UserVisit::query()->join('users', 'users.id', '=', 'user_visits.user_id')->whereIn('users.game_id', $ids)->where('user_visits.at', '>=', $week)
            ->selectRaw('users.game_id as game_id, count(distinct user_visits.user_id) as active_week, count(distinct case when user_visits.at >= ? then user_visits.user_id end) as active_day', [$day])
            ->groupBy('users.game_id')->get();

        foreach ($visits as $row) {
            $stats[(int) $row->game_id]['active_week'] = (int) $row->active_week;
            $stats[(int) $row->game_id]['active_day'] = (int) $row->active_day;
        }

        // What was bought and paid for, in the currency it was paid in: a game may be sold in any, so the sums are never added across currencies.
        $revenue = Purchase::query()->withoutGlobalScopes()->join('users', 'users.id', '=', 'purchases.user_id')->whereIn('users.game_id', $ids)->where('purchases.status', Purchase::STATUS_PAID)
            ->selectRaw('users.game_id as game_id, purchases.currency as currency, sum(purchases.amount_minor) as total, sum(case when purchases.created_at >= ? then purchases.amount_minor else 0 end) as month', [$month])
            ->groupBy('users.game_id', 'purchases.currency')->get();

        foreach ($revenue as $row) {
            $stats[(int) $row->game_id]['revenue_total'][(string) $row->currency] = (int) $row->total;
            $stats[(int) $row->game_id]['revenue_month'][(string) $row->currency] = (int) $row->month;
        }

        foreach ($games as $game) {
            $stats[$game->id]['pack'] = $this->current->within($game, fn (): ?string => $this->packs->active()?->number);
        }

        return $stats;
    }

    /**
     * The numbers of all the games added up (the money is added up per currency).
     *
     * @param  array<int, array{accounts: int, guests: int, secured: int, new_week: int, active_day: int, active_week: int, revenue_total: array<string, int>, revenue_month: array<string, int>, pack: string|null}>  $stats
     * @return array{accounts: int, guests: int, secured: int, new_week: int, active_day: int, active_week: int, revenue_total: array<string, int>, revenue_month: array<string, int>}
     */
    public static function totals(array $stats): array
    {
        $totals = ['accounts' => 0, 'guests' => 0, 'secured' => 0, 'new_week' => 0, 'active_day' => 0, 'active_week' => 0, 'revenue_total' => [], 'revenue_month' => []];

        foreach ($stats as $game) {
            foreach (['accounts', 'guests', 'secured', 'new_week', 'active_day', 'active_week'] as $key) {
                $totals[$key] += $game[$key];
            }

            foreach (['revenue_total', 'revenue_month'] as $key) {
                foreach ($game[$key] as $currency => $minor) {
                    $totals[$key][$currency] = ($totals[$key][$currency] ?? 0) + $minor;
                }
            }
        }

        return $totals;
    }

    /**
     * Money by currency as a line: "12,50 PLN + 3,00 USD", or the word for nothing when there is none.
     *
     * @param  array<string, int>  $minor
     */
    public static function money(array $minor, string $none): string
    {
        $parts = [];

        foreach ($minor as $currency => $amount) {
            if ($amount > 0) {
                $parts[] = number_format($amount / 100, 2, ',', ' ').' '.$currency;
            }
        }

        return $parts === [] ? $none : implode(' + ', $parts);
    }
}
