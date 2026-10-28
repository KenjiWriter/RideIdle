<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Widgets\LivePackWidget;
use App\Support\Locale;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

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
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountWidget::class,
                LivePackWidget::class,
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
            ]);
    }
}
