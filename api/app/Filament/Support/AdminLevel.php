<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Admin;
use Illuminate\Support\Facades\Auth;

/**
 * The two levels of the panel. The network is about the installation (the games that it runs, who made them, what they bring in); a game is about one of them (its
 * content, its accounts). What the menu offers depends on the level of the page that is open, which the address says, so there is nothing to keep in the session and
 * a page opened from a bookmark is in the right menu. An admin who may work in one game only has no network: the games, and the way to them, are not theirs.
 */
final class AdminLevel
{
    /** The parts of the names of the routes of the pages of the network level. */
    private const NETWORK_ROUTES = ['.pages.network-dashboard', '.resources.games.'];

    /** Whether the admin who is signed in has a network at all: they may work in every game, or in more than one. */
    public static function hasNetwork(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin && ($admin->all_games || $admin->games()->count() > 1);
    }

    /** Whether the page that is open is of the network level (and the admin has one): the menu is the one of the network. */
    public static function isNetwork(?string $routeName = null): bool
    {
        if (! self::hasNetwork()) {
            return false;
        }

        $name = $routeName ?? (string) request()->route()?->getName();

        foreach (self::NETWORK_ROUTES as $part) {
            if (str_contains($name, $part)) {
                return true;
            }
        }

        return false;
    }
}
