<?php

declare(strict_types=1);

namespace App\Games;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** What belongs to a game is seen in that game only: the game in play (`CurrentGame`) is the one asked for. */
final class GameScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('game_id'), app(CurrentGame::class)->id());
    }
}
