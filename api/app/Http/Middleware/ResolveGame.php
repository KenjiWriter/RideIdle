<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Games\CurrentGame;
use App\Games\Games;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the game that a request of the API is for (by its host, see `Games::forRequest`) and puts it in force, before anything reads the accounts or the content: a
 * token of one game finds no account in another, and each game reads its own content. A request that no game claims is refused.
 */
final class ResolveGame
{
    public function __construct(private readonly Games $games, private readonly CurrentGame $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $game = $this->games->forRequest($request);

        if ($game === null) {
            return response()->json(['message' => 'There is no game here.', 'code' => 'unknown_game'], 404);
        }

        $this->current->set($game);

        return $next($request);
    }
}
