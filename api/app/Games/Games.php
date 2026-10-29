<?php

declare(strict_types=1);

namespace App\Games;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** The games of this installation, and which of them a request is for. */
final class Games
{
    /** @var Collection<int, Game>|null */
    private ?Collection $all = null;

    /** @return Collection<int, Game> every game, the first one first */
    public function all(): Collection
    {
        return $this->all ??= Game::query()->orderBy('id')->get();
    }

    public function bySlug(string $slug): ?Game
    {
        return $this->all()->first(fn (Game $game): bool => $game->slug === $slug);
    }

    /** The live game that answers on a host (a game that is a draft answers to nobody). */
    public function byHost(string $host): ?Game
    {
        $host = strtolower($host);

        return $this->all()->first(fn (Game $game): bool => $game->isLive() && $game->domain !== null && strtolower($game->domain) === $host);
    }

    /** The game that answers where nothing says which one is meant. */
    public function default(): ?Game
    {
        return $this->all()->first(fn (Game $game): bool => $game->is_default);
    }

    /**
     * The game that a request is for, or null when there is none and the request is to be refused. In development a header (`X-Game`, with the slug) says it; then the
     * host does; an installation that has one game only is that game whatever the host is; and in development a host that nobody claims is the default game, while in
     * production it is nobody's (a domain that points here and that no game has is a mistake, not the first game).
     */
    public function forRequest(Request $request): ?Game
    {
        if (! app()->isProduction() && $request->headers->has('X-Game')) {
            return $this->bySlug((string) $request->headers->get('X-Game'));
        }

        $byHost = $this->byHost($request->getHost());

        if ($byHost !== null) {
            return $byHost;
        }

        if ($this->all()->count() === 1) {
            return $this->all()->first();
        }

        return app()->isProduction() ? null : $this->default();
    }
}
