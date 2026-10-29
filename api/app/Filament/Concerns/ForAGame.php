<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Support\AdminLevel;

/** A page or a resource that is about one game: it is in the menu of a game, and not in the menu of the network. */
trait ForAGame
{
    public static function shouldRegisterNavigation(): bool
    {
        return ! AdminLevel::isNetwork() && parent::shouldRegisterNavigation();
    }
}
