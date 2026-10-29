<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\ForAGame;
use App\Filament\Widgets\GameStatsWidget;
use App\Filament\Widgets\LivePackWidget;
use BackedEnum;
use Filament\Pages\Dashboard;
use Illuminate\Contracts\Support\Htmlable;

/** The home of a game in the panel: its accounts, how many of them come back, what it brings in, and the pack that it plays now. */
class GameOverview extends Dashboard
{
    use ForAGame;

    protected static string $routePath = '/game';

    protected static ?int $navigationSort = -2;

    public static function getNavigationLabel(): string
    {
        return __('admin.overview.nav');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-home';
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.overview.title');
    }

    public function getWidgets(): array
    {
        return [GameStatsWidget::class, LivePackWidget::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
