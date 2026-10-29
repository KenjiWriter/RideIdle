<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\Admin;
use App\Models\Game;

/**
 * The part of the commands that make and change admins that says which games the admin may work in: `--all-games`, or `--game=<slug>` (as many times as there are
 * games) with the role the admin has in that game when it is not their own (`--game=statki:publisher`). An admin who is given games is one of those games only.
 */
trait GivesAccessToGames
{
    /**
     * The games that were asked for, by id, with the role in each (null: the role of the admin), or null when there is something wrong with them (it is said).
     *
     * @return array<int, string|null>|null
     */
    protected function chosenGames(): ?array
    {
        $chosen = [];

        foreach ((array) $this->option('game') as $given) {
            [$slug, $role] = array_pad(explode(':', (string) $given, 2), 2, null);
            $game = Game::query()->where('slug', $slug)->first();

            if ($game === null) {
                $this->error("There is no game {$slug}.");

                return null;
            }

            if ($role !== null && ! in_array($role, Admin::ROLES, true)) {
                $this->error('The role in a game is '.implode(' or ', Admin::ROLES).'.');

                return null;
            }

            $chosen[$game->id] = $role;
        }

        if ($chosen !== [] && $this->option('all-games')) {
            $this->error('An admin is of every game (--all-games) or of the games that are given (--game), not both.');

            return null;
        }

        return $chosen;
    }

    /**
     * Gives an admin the games: none given leaves the access as it is (except --all-games, which makes them an admin of every game and takes the single games off
     * them). What the admin may enter after it is returned, for the command to say.
     *
     * @param  array<int, string|null>  $chosen
     */
    protected function giveAccess(Admin $admin, array $chosen): ?string
    {
        if ($this->option('all-games')) {
            $admin->forceFill(['all_games' => true])->save();
            $admin->games()->detach();

            return 'every game';
        }

        if ($chosen === []) {
            return null;
        }

        $admin->forceFill(['all_games' => false])->save();
        $admin->games()->sync(array_map(static fn (?string $role): array => ['role' => $role], $chosen));

        return $this->describeAccess($admin->refresh());
    }

    protected function describeAccess(Admin $admin): string
    {
        if ($admin->all_games) {
            return 'every game';
        }

        return $admin->games()->orderBy('games.id')->get()->map(static fn (Game $game): string => $game->slug.' ('.($game->pivot->role ?? $admin->role).')')->implode(', ');
    }
}
