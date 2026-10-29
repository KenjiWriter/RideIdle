<?php

declare(strict_types=1);

namespace App\Games;

use App\Models\Game;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row that belongs to one game: it is read in that game only (the game in play, see `CurrentGame`), and is written in it unless the game is given. A query that is
 * about every game says so with `withoutGlobalScopes()`.
 *
 * @mixin Model
 */
trait BelongsToGame
{
    public static function bootBelongsToGame(): void
    {
        static::addGlobalScope(new GameScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('game_id') === null) {
                $model->setAttribute('game_id', app(CurrentGame::class)->id());
            }
        });
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
