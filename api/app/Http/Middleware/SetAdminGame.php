<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Games\CurrentGame;
use App\Models\Admin;
use App\Models\Game;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts in force the game that an admin works in, after they are signed in: the one they chose (its slug is in the session, see the switcher in the top bar) if they
 * may enter it, else the default game if they may, else the first game they may. What the panel lists and saves from then on is that game's. An admin who may enter no
 * game is shown nothing but a refusal. It is also a persistent middleware of the components of the panel, so that what a component does is done in the same game as
 * the page it is on.
 */
final class SetAdminGame
{
    /** The key of the session in which the slug of the game that the admin chose is kept. */
    public const SESSION_KEY = 'admin_game';

    public function __construct(private readonly CurrentGame $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if ($admin instanceof Admin) {
            $this->current->set($this->gameOf($admin));
        }

        return $next($request);
    }

    /** The game that an admin works in now (a refusal when there is none that they may). */
    private function gameOf(Admin $admin): Game
    {
        $allowed = $admin->allowedGames();
        $chosen = session(self::SESSION_KEY);

        return $allowed->first(fn (Game $game): bool => $game->slug === $chosen)
            ?? $allowed->first(fn (Game $game): bool => $game->is_default)
            ?? $allowed->first()
            ?? abort(403, __('admin.games.no_access'));
    }
}
