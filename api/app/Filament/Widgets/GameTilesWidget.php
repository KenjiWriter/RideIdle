<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\GameOverview;
use App\Filament\Resources\Games\GameResource;
use App\Http\Middleware\SetAdminGame;
use App\Models\Admin;
use App\Models\Game;
use App\Services\NetworkStats;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/** The games that the admin may enter, one tile each, with what is in them. A tile is the way into the game, and the last one is the way to a new game. */
class GameTilesWidget extends Widget
{
    protected string $view = 'filament.network.tiles';

    protected int|string|array $columnSpan = 'full';

    /** Takes the admin into a game: it is the game that the panel works in from then on, and what they see first is its overview. */
    public function enter(string $slug): void
    {
        $admin = Auth::guard('admin')->user();
        $game = Game::query()->where('slug', $slug)->first();

        abort_unless($admin instanceof Admin && $game !== null && $admin->canEnter($game), 403, __('admin.games.no_access'));

        session()->put(SetAdminGame::SESSION_KEY, $game->slug);

        $this->redirect(GameOverview::getUrl());
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $admin = Auth::guard('admin')->user();
        $games = $admin instanceof Admin ? $admin->allowedGames() : collect();
        $stats = app(NetworkStats::class)->for($games);

        return [
            'tiles' => $games->map(fn (Game $game): array => [
                'slug' => $game->slug,
                'name' => $game->name,
                'domain' => $game->domain,
                'live' => $game->isLive(),
                'stats' => $stats[$game->id],
                'revenue' => NetworkStats::money($stats[$game->id]['revenue_month'], __('admin.network.stats.no_revenue')),
            ])->values()->all(),
            'canCreate' => $admin instanceof Admin && $admin->all_games,
            'createUrl' => GameResource::getUrl('create'),
        ];
    }
}
