<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Support\AdminLevel;

/** A page or a resource that is about the installation: it is in the menu of the network, and in no menu of a game. */
trait ForTheNetwork
{
    public static function shouldRegisterNavigation(): bool
    {
        return AdminLevel::isNetwork() && parent::shouldRegisterNavigation();
    }
}
