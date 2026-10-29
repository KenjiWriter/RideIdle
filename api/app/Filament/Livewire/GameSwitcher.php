<?php

declare(strict_types=1);

namespace App\Filament\Livewire;

use App\Filament\Pages\GameOverview;
use App\Games\CurrentGame;
use App\Http\Middleware\SetAdminGame;
use App\Models\Admin;
use App\Models\Game;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The game that the panel works in, in the top bar: the games that the admin may enter, the one in play chosen. Changing it keeps the slug in the session (see
 * `SetAdminGame`) and takes the admin to the overview of that game. An admin who may enter one game sees its name and no choice.
 */
class GameSwitcher extends Component
{
    /** The slug of the game in play. */
    public string $slug = '';

    public function mount(): void
    {
        $this->slug = app(CurrentGame::class)->game()?->slug ?? (string) $this->games()->first(fn (Game $game): bool => $game->is_default)?->slug;
    }

    public function updatedSlug(string $slug): void
    {
        $game = $this->games()->first(fn (Game $candidate): bool => $candidate->slug === $slug);

        abort_if($game === null, 403, __('admin.games.no_access'));

        session()->put(SetAdminGame::SESSION_KEY, $game->slug);

        // A game that is chosen is a game that is entered: what is shown first is its overview.
        $this->redirect(GameOverview::getUrl());
    }

    /** @return Collection<int, Game> the games that the admin may enter, the first one first */
    private function games(): Collection
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin ? $admin->allowedGames() : new Collection;
    }

    public function render(): View
    {
        return view('filament.games.switcher', ['games' => $this->games()]);
    }
}
