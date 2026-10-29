<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Livewire\GameSwitcher;
use App\Filament\Pages\NetworkDashboard;
use App\Filament\Support\AdminLevel;
use App\Filament\Widgets\LivePackWidget;
use App\Http\Middleware\SetAdminGame;
use App\Support\Locale;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

/**
 * The panel of the people who run the content of the game. It is not for the players: its own guard and its own accounts (the `admins` table), a password and
 * the code of an authenticator app at every sign-in, a trail of what was done, and a host of its own when `ride.admin_domain` is set (which keeps its cookies
 * away from the game); without one it is /admin on the host of the application.
 */
class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // The language of the panel is that of the people who run it, not of a player's request: this runs for every request the panel answers, the ones of its
        // components too.
        Filament::serving(fn () => app()->setLocale(Locale::toLaravel(Locale::normalize((string) config('ride.admin_locale')) ?? Locale::default())));

        Livewire::component('game-switcher', GameSwitcher::class);
    }

    public function panel(Panel $panel): Panel
    {
        $domain = config('ride.admin_domain');

        return $panel
            ->default()
            ->id('admin')
            ->domain($domain)
            ->path($domain === null ? 'admin' : '')
            ->authGuard('admin')
            ->login()
            ->profile()
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable()->brandName((string) config('app.name')),
            ], isRequired: true)
            ->brandName('RideIdle')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                LivePackWidget::class,
            ])
            // The way back from a game to the network, where there is a network to go back to.
            ->navigationItems([
                NavigationItem::make()
                    ->label(fn (): string => __('admin.network.back'))
                    ->icon('heroicon-o-arrow-left-circle')
                    ->url(fn (): string => NetworkDashboard::getUrl())
                    ->visible(fn (): bool => AdminLevel::hasNetwork() && ! AdminLevel::isNetwork())
                    ->sort(-10),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // The game that the admin works in is put in force once they are signed in, and for the components of the page too (they are asked for in requests of their own).
            ->authMiddleware([
                SetAdminGame::class,
            ], isPersistent: true)
            // The game that the panel works in is chosen at the top of every page.
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => Blade::render("@livewire('game-switcher')"))
            // Where the admin is, on top of the menu: the network, or the game that the menu is about.
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn (): string => view('filament.games.sidebar-head')->render());
    }
}
