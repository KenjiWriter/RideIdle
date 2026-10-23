<?php

namespace App\Providers;

use App\Content\Catalog;
use App\Content\Rules;
use App\Services\Accounts;
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

        // An e-mail with a link can be sent to anybody, so it is limited per account, per address (hashed, so the
        // address is not kept) and per network address.
        // The secret of a link has 256 bits, so it cannot be guessed; the limit only keeps a flood of requests away.
        RateLimiter::for('account-confirm', fn (Request $request) => Limit::perHour(30)->by((string) $request->ip()));

        RateLimiter::for('account-login-link', fn (Request $request) => [
            Limit::perHour(3)->by('address:'.sha1(Accounts::normalizeEmail($request->string('email')->toString()))),
            Limit::perHour(10)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('account-email', fn (Request $request) => [
            Limit::perHour(5)->by('user:'.$request->user()?->getAuthIdentifier()),
            Limit::perHour(3)->by('address:'.sha1(Accounts::normalizeEmail($request->string('email')->toString()))),
            Limit::perHour(30)->by('ip:'.$request->ip()),
        ]);
    }
}
