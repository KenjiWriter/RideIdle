<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Admin;
use App\Services\NetworkStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/** The games of the network added up, for the games that the admin may enter. */
class NetworkOverviewWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $admin = Auth::guard('admin')->user();
        $games = $admin instanceof Admin ? $admin->allowedGames() : collect();
        $totals = NetworkStats::totals(app(NetworkStats::class)->for($games));
        $live = $games->filter(fn ($game): bool => $game->isLive())->count();

        return [
            Stat::make(__('admin.network.stats.games'), (string) $games->count())
                ->description(__('admin.network.stats.games_hint', ['live' => $live, 'draft' => $games->count() - $live])),
            Stat::make(__('admin.network.stats.accounts'), number_format($totals['accounts'], 0, ',', ' '))
                ->description(__('admin.network.stats.accounts_hint', ['guests' => number_format($totals['guests'], 0, ',', ' ')])),
            Stat::make(__('admin.network.stats.new_week'), number_format($totals['new_week'], 0, ',', ' ')),
            Stat::make(__('admin.network.stats.active_week'), number_format($totals['active_week'], 0, ',', ' '))
                ->description(__('admin.network.stats.active_hint', ['day' => number_format($totals['active_day'], 0, ',', ' ')])),
            Stat::make(__('admin.network.stats.revenue'), NetworkStats::money($totals['revenue_month'], __('admin.network.stats.no_revenue')))
                ->description(__('admin.network.stats.revenue_hint')),
        ];
    }
}
