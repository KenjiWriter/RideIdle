<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\ContentException;
use App\Content\Rules;
use Illuminate\Container\Attributes\Scoped;

/**
 * The level that an experience reaches in the game that is open. The curve is in the rules of the game, and a game that has no live pack (a new one, still empty) has no
 * rules to ask, so then there is no level to give: the panel shows the accounts of such a game all the same.
 */
#[Scoped]
final class PlayerLevels
{
    private bool $unavailable = false;

    public function of(int $xp): ?int
    {
        if ($this->unavailable) {
            return null;
        }

        try {
            return app(Rules::class)->playerLevelAt($xp);
        } catch (ContentException) {
            $this->unavailable = true;

            return null;
        }
    }
}
