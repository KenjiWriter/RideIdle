<?php

declare(strict_types=1);

namespace App\Games;

use App\Content\BotProfiles;
use App\Content\Catalog;
use App\Content\ContentSource;
use App\Content\Rules;
use App\Content\TutorialSteps;
use App\Content\Vocabulary\Vocabulary;
use App\Models\Game;
use Closure;
use Illuminate\Database\QueryException;

/**
 * The game that the work in hand is for: a request says it by its host (see `ResolveGame`), the panel by the game that the admin chose, a command by `--game`.
 * Where nothing says, it is the default game, so that a command or a test that knows of one game only works as it always did. What belongs to a game (its content, its
 * accounts) is read and written in this one (`BelongsToGame`); to look across games, say so with `withoutGlobalScopes()`.
 */
final class CurrentGame
{
    private ?Game $game = null;

    private ?int $defaultId = null;

    /** Puts a game in force for what follows (null: the default game again). When the game is another one, what was read of the content of the one before is forgotten. */
    public function set(?Game $game): void
    {
        $before = $this->id();
        $this->game = $game;

        if ($this->id() !== $before) {
            $this->forgetContent();
        }
    }

    /** The game that was put in force, or null when none was (the default game is then the one in play). */
    public function game(): ?Game
    {
        return $this->game;
    }

    /** The id of the game in play: the one that was put in force, else the default one. */
    public function id(): int
    {
        return $this->game?->id ?? $this->defaultId();
    }

    /** Whether the game in play is the default one. */
    public function isDefault(): bool
    {
        return $this->id() === $this->defaultId();
    }

    /**
     * The address of the web app of the game in play: the one of this installation (`WEB_URL`) for the default game and for a game without a domain, else the domain of
     * the game, with the scheme of `WEB_URL`. It is where the links in an e-mail and the return from another service lead: to the app of the game of the account.
     */
    public function webUrl(): string
    {
        $base = (string) config('ride.web_url');
        $domain = $this->game?->domain;

        if ($this->game === null || $this->game->is_default || blank($domain)) {
            return $base;
        }

        return (parse_url($base, PHP_URL_SCHEME) ?: 'https').'://'.$domain;
    }

    /**
     * Where another service sends the person back to, for a game that has a domain of its own: the callback of the API on that domain (the provider has the
     * address of each game registered). Null for the first game, whose address is the one of the configuration.
     */
    public function callbackUrl(string $provider): ?string
    {
        $base = $this->webUrl();

        return $base === (string) config('ride.web_url') ? null : "{$base}/api/v1/auth/{$provider}/callback";
    }

    /**
     * Does the work for a game and gives the one that was in force back after it.
     *
     * @template T
     *
     * @param  Closure(): T  $do
     * @return T
     */
    public function within(Game|int $game, Closure $do): mixed
    {
        $before = $this->game;
        $this->set($game instanceof Game ? $game : Game::query()->findOrFail($game));

        try {
            return $do();
        } finally {
            $this->set($before);
        }
    }

    /** The content that was read for a game is that game's: the sources, the catalog and the rules are read again for the game that is in play now. */
    private function forgetContent(): void
    {
        foreach ([ContentSource::class, Catalog::class, Rules::class, BotProfiles::class, TutorialSteps::class, Vocabulary::class] as $abstract) {
            app()->forgetInstance($abstract);
        }
    }

    private function defaultId(): int
    {
        if ($this->defaultId !== null) {
            return $this->defaultId;
        }

        try {
            return $this->defaultId = (int) (Game::query()->where('is_default', true)->value('id') ?? 1);
        } catch (QueryException) {
            // The games are not there yet (the content is checked before the migrations of a deploy): there is the first game only.
            return 1;
        }
    }
}
