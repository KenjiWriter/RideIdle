<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Games\CurrentGame;
use App\Models\Game;
use App\Services\Metrics;
use App\Services\NetworkStats;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** What the game in play has in it: the accounts, who came back, what was bought, and how many of the accounts of the last month return after a day and after a week. */
class GameStatsWidget extends StatsOverviewWidget
{
    /** The accounts made in this many last days are the ones whose return is told. */
    private const COHORT_DAYS = 30;

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = ['default' => 1, 'sm' => 2, 'xl' => 4];

    protected function getStats(): array
    {
        $game = Game::query()->find(app(CurrentGame::class)->id());
        $stats = $game === null ? null : app(NetworkStats::class)->for(collect([$game]))[$game->id];

        $now = CarbonImmutable::now();
        $retention = app(Metrics::class)->report($now->startOfDay()->subDays(self::COHORT_DAYS - 1), $now->addSecond(), $now)['retention'];
        $rate = fn (string $day): string => isset($retention[$day]['rate']) ? number_format($retention[$day]['rate'] * 100, 1, ',', ' ').'%' : '-';

        return [
            Stat::make(__('admin.overview.stats.accounts'), number_format($stats['accounts'] ?? 0, 0, ',', ' '))
                ->description(__('admin.network.stats.accounts_hint', ['guests' => number_format($stats['guests'] ?? 0, 0, ',', ' ')])),
            Stat::make(__('admin.network.stats.new_week'), number_format($stats['new_week'] ?? 0, 0, ',', ' ')),
            Stat::make(__('admin.overview.stats.active_day'), number_format($stats['active_day'] ?? 0, 0, ',', ' ')),
            Stat::make(__('admin.network.stats.active_week'), number_format($stats['active_week'] ?? 0, 0, ',', ' ')),
            Stat::make(__('admin.overview.stats.retention_d1'), $rate('d1'))->description(__('admin.overview.stats.retention_hint', ['days' => self::COHORT_DAYS])),
            Stat::make(__('admin.overview.stats.retention_d7'), $rate('d7'))->description(__('admin.overview.stats.retention_hint', ['days' => self::COHORT_DAYS])),
            Stat::make(__('admin.network.stats.revenue'), NetworkStats::money($stats['revenue_month'] ?? [], __('admin.network.stats.no_revenue')))
                ->description(__('admin.network.stats.revenue_hint')),
        ];
    }
}
