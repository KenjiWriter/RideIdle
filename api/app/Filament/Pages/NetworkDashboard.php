<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\ForTheNetwork;
use App\Filament\Support\AdminLevel;
use App\Filament\Widgets\GameTilesWidget;
use App\Filament\Widgets\NetworkOverviewWidget;
use BackedEnum;
use Filament\Pages\Dashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The home of the panel: the games of the installation as tiles, with what each has in it, and the numbers of all of them together. A game is entered from here, and a new one
 * is made from here. An admin who may work in one game has no network, and is taken to the overview of that game.
 */
class NetworkDashboard extends Dashboard
{
    use ForTheNetwork;

    protected static ?int $navigationSort = -2;

    public static function getNavigationLabel(): string
    {
        return __('admin.network.nav');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-globe-alt';
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.network.title');
    }

    public function mount(): void
    {
        if (! AdminLevel::hasNetwork()) {
            $this->redirect(GameOverview::getUrl());
        }
    }

    public function getWidgets(): array
    {
        return [NetworkOverviewWidget::class, GameTilesWidget::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
