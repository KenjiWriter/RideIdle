<?php

namespace App\Providers;

use App\Content\Catalog;
use App\Content\Rules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The content is read once per process.
        $this->app->singleton(Catalog::class, fn () => Catalog::fromConfig());
        $this->app->singleton(Rules::class, fn () => Rules::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Creating a guest is free, so it is limited per address.
        RateLimiter::for('guest-create', fn (Request $request) => Limit::perHour(10)->by((string) $request->ip()));

        // Recovery codes can be guessed, so attempts are limited per address; issuing is limited per account.
        RateLimiter::for('recovery-attempts', fn (Request $request) => Limit::perHour(5)->by((string) $request->ip()));
        RateLimiter::for('recovery-issue', fn (Request $request) => Limit::perHour(5)->by((string) $request->user()?->getAuthIdentifier()));
    }
}
