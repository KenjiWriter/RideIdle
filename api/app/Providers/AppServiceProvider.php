<?php

namespace App\Providers;

use App\Content\BotProfiles;
use App\Content\Catalog;
use App\Content\ContentSource;
use App\Content\Rules;
use App\Content\TutorialSteps;
use App\Services\Accounts;
use App\Services\ContentPacks;
use App\Services\Ledger;
use App\Services\RunService;
use App\Services\TutorialScript;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Apple\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The content is read once per request, and again for every job of a queue worker, which lives for hours: the pack that is live can change in between.
        $this->app->scoped(ContentSource::class, fn ($app) => $app->make(ContentPacks::class)->configured());
        $this->app->scoped(Catalog::class, fn () => Catalog::fromConfig());
        $this->app->scoped(Rules::class, fn () => Rules::fromConfig());
        $this->app->scoped(BotProfiles::class, fn () => BotProfiles::fromConfig());
        // The container does not fill the optional parameters of the run service, so the arrangements of the tutorial are given to the one of the application here:
        // a service that is built by hand (in a test, for a run of its own) leaves them out.
        $this->app->bind(RunService::class, fn ($app) => new RunService(ledger: $app->make(Ledger::class), script: $app->make(TutorialScript::class)));
        $this->app->scoped(TutorialSteps::class, fn () => TutorialSteps::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Apple is not one of the providers Socialite has built in.
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('apple', Provider::class);
        });

        // Creating a guest is free, so it is limited per address.
        RateLimiter::for('guest-create', fn (Request $request) => Limit::perHour(10)->by((string) $request->ip()));

        // Recovery codes can be guessed, so attempts are limited per address; issuing is limited per account.
        RateLimiter::for('recovery-attempts', fn (Request $request) => Limit::perHour(5)->by((string) $request->ip()));
        RateLimiter::for('recovery-issue', fn (Request $request) => Limit::perHour(5)->by((string) $request->user()?->getAuthIdentifier()));

        // An e-mail with a link can be sent to anybody, so it is limited per account, per address (hashed, so the
        // address is not kept) and per network address.
        // The secret of a link has 256 bits, so it cannot be guessed; the limit only keeps a flood of requests away.
        RateLimiter::for('account-confirm', fn (Request $request) => Limit::perHour(30)->by((string) $request->ip()));

        // Starting a sign-in with a provider is cheap for us and nothing for the provider, so it is limited per network
        // address, and per account when there is one (a device with no account is told apart by its address only).
        RateLimiter::for('account-oauth-start', fn (Request $request) => [
            Limit::perHour(60)->by('ip:'.$request->ip()),
            ...($request->user() ? [Limit::perHour(20)->by('user:'.$request->user()->getAuthIdentifier())] : []),
        ]);

        // A copy of the data is a query over everything the account did, and deleting is not to be tried over and over.
        RateLimiter::for('account-data', fn (Request $request) => Limit::perHour(10)->by('user:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('account-erase', fn (Request $request) => Limit::perHour(5)->by('user:'.$request->user()?->getAuthIdentifier()));

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
