<?php

declare(strict_types=1);

use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Models\User;
use App\Services\AccountTokens;
use App\Services\SocialSignIn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function () {
    config(['ride.web_url' => 'https://web.example']);
    withProviders(providerKeys());
});

afterEach(fn () => Carbon::setTestNow());

describe('starting to link a provider', function () {
    it('is for a driver with a device token only', function () {
        $this->postJson('/api/v1/account/identities/google/start', ['accept_terms' => true, 'confirm_age' => true])->assertUnauthorized();
    });

    it('needs the terms accepted and the age confirmed', function (array $body, string $missing) {
        $driver = User::factory()->guest()->create();

        startProviderLink($driver, 'google', $body)->assertUnprocessable()->assertJsonValidationErrors([$missing]);

        expect(AccountToken::query()->count())->toBe(0);
    })->with([
        'terms' => [['accept_terms' => false], 'accept_terms'],
        'age' => [['confirm_age' => false], 'confirm_age'],
    ]);

    it('gives the address to send the browser to, with our state, and a verifier for the browser to keep', function (string $provider, string $host) {
        $driver = User::factory()->guest()->create();

        $response = startProviderLink($driver, $provider)->assertOk();
        $url = $response->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect(parse_url($url, PHP_URL_HOST))->toBe($host)
            ->and($query['redirect_uri'])->toBe("https://api.example/api/v1/auth/{$provider}/callback")
            ->and($response->json('verifier'))->toMatch('/^[A-Za-z0-9_-]{43}$/')
            ->and($url)->not->toContain($response->json('verifier'));
    })->with([
        'google' => ['google', 'accounts.google.com'],
        'facebook' => ['facebook', 'www.facebook.com'],
        'apple' => ['apple', 'appleid.apple.com'],
    ]);

    it('remembers the flow by its state, for this account, with the moment the terms were accepted, and keeps the hash of the verifier only', function () {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $driver = User::factory()->guest()->create();

        $response = startProviderLink($driver, 'google')->assertOk();
        $state = AccountToken::query()->where('token_hash', AccountTokens::hash(stateOf($response->json('url'))))->firstOrFail();

        expect($state->purpose)->toBe('oauth')
            ->and($state->provider)->toBe('google')
            ->and($state->intent)->toBe('link')
            ->and($state->user_id)->toBe($driver->id)
            ->and($state->consent_at->equalTo(now()))->toBeTrue()
            ->and($state->expires_at->equalTo(now()->addMinutes(10)))->toBeTrue()
            ->and($state->challenge)->toBe(AccountTokens::hash($response->json('verifier')))
            ->and(AccountToken::query()->where('token_hash', $response->json('verifier'))->orWhere('challenge', $response->json('verifier'))->exists())->toBeFalse();
    });

    it('does not ask an account that has accepted the terms and confirmed its age for them again', function () {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $driver = User::factory()->create(['claimed_at' => now(), 'terms_accepted_at' => now()->subWeek(), 'age_confirmed_at' => now()->subWeek()]);
        identityOf($driver, 'email', 'turbo@example.com');

        $response = test()->postJson('/api/v1/account/identities/google/start', [], bearerFor($driver))->assertOk();
        $state = AccountToken::query()->where('token_hash', AccountTokens::hash(stateOf($response->json('url'))))->firstOrFail();

        // Nothing new to record: the moments it accepted them stay as they were.
        expect($state->consent_at)->toBeNull()->and($state->user_id)->toBe($driver->id);
    });

    it('asks an account for them when they are not both on record', function () {
        $driver = User::factory()->create(['claimed_at' => now(), 'terms_accepted_at' => now(), 'age_confirmed_at' => null]);

        test()->postJson('/api/v1/account/identities/google/start', [], bearerFor($driver))
            ->assertUnprocessable()->assertJsonValidationErrors(['confirm_age']);

        expect(AccountToken::query()->count())->toBe(0);
    });

    it('gives a different state and verifier every time', function () {
        $driver = User::factory()->guest()->create();

        $first = startProviderLink($driver, 'google')->json();
        $second = startProviderLink($driver, 'google')->json();

        expect(stateOf($first['url']))->not->toBe(stateOf($second['url']))
            ->and($first['verifier'])->not->toBe($second['verifier']);
    });

    it('has Apple check a nonce made from the state', function () {
        $driver = User::factory()->guest()->create();

        $url = startProviderLink($driver, 'apple')->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($query['nonce'])->toBe(SocialSignIn::nonce($query['state']))
            ->and($query['response_mode'])->toBe('form_post');
    });

    it('does not start a provider that is not set up', function () {
        withProviders(['facebook' => providerKeys()['facebook']]);
        $driver = User::factory()->guest()->create();

        startProviderLink($driver, 'google')->assertNotFound()->assertJsonPath('code', 'provider_unavailable');

        expect(AccountToken::query()->count())->toBe(0);
    });

    it('does not know a provider that is not one of the three', function () {
        $driver = User::factory()->guest()->create();

        startProviderLink($driver, 'github')->assertNotFound();
    });

    it('does not link a second account of the same provider', function () {
        $driver = User::factory()->guest()->create();
        identityOf($driver, 'google', '1098765', 'turbo@example.com');

        startProviderLink($driver, 'google')->assertConflict()->assertJsonPath('code', 'provider_already_linked');

        expect(AccountToken::query()->count())->toBe(0);
    });

    it('is limited for one account', function () {
        $driver = User::factory()->guest()->create();

        foreach (range(1, 20) as $ignored) {
            startProviderLink($driver, 'google')->assertOk();
        }

        startProviderLink($driver, 'google')->assertTooManyRequests();
    });
});

describe('starting to sign in with a provider', function () {
    it('is open to a device that has no account, and is not for any account', function () {
        Carbon::setTestNow('2026-10-23 12:00:00');

        $response = startProviderLogin('facebook')->assertOk();
        $state = AccountToken::query()->where('token_hash', AccountTokens::hash(stateOf($response->json('url'))))->firstOrFail();

        expect($state->intent)->toBe('login')
            ->and($state->provider)->toBe('facebook')
            ->and($state->user_id)->toBeNull()
            ->and($state->consent_at)->toBeNull()
            ->and($state->challenge)->toBe(AccountTokens::hash($response->json('verifier')));
    });

    it('does not start a provider that is not set up, or that is not one of the three', function () {
        withProviders([]);

        startProviderLogin('google')->assertNotFound()->assertJsonPath('code', 'provider_unavailable');
        startProviderLogin('github')->assertNotFound();

        expect(AccountToken::query()->count())->toBe(0);
    });

    it('is limited for one network address', function () {
        foreach (range(1, 60) as $ignored) {
            startProviderLogin('google')->assertOk();
        }

        startProviderLogin('google')->assertTooManyRequests();
    });
});

describe('coming back from the provider', function () {
    it('keeps who the provider says the person is for the browser that started, and changes no account', function () {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $driver = User::factory()->guest()->create();
        $start = startProviderLink($driver, 'google')->json();
        providerSays('google', ['id' => '1098765', 'email' => 'Turbo@Example.COM']);

        $response = comesBackFrom('google', stateOf($start['url']));

        $fields = fragmentOf($response);
        $ticket = AccountToken::query()->where('purpose', 'oauth_result')->firstOrFail();

        expect($fields)->toHaveKeys(['result', 'provider', 'ticket'])
            ->and($fields['result'])->toBe('ready')
            ->and($fields['provider'])->toBe('google')
            ->and($ticket->token_hash)->toBe(AccountTokens::hash($fields['ticket']))
            ->and($ticket->provider)->toBe('google')
            ->and($ticket->subject)->toBe('1098765')
            ->and($ticket->email)->toBe('turbo@example.com')
            ->and($ticket->intent)->toBe('link')
            ->and($ticket->user_id)->toBe($driver->id)
            ->and($ticket->consent_at->equalTo(now()))->toBeTrue()
            ->and($ticket->challenge)->toBe(AccountTokens::hash($start['verifier']))
            ->and($ticket->expires_at->equalTo(now()->addMinutes(5)))->toBeTrue()
            // Whoever comes back, nothing happened to any account.
            ->and(AccountIdentity::query()->count())->toBe(0)
            ->and($driver->fresh()->claimed_at)->toBeNull()
            ->and(User::query()->count())->toBe(1);
    });

    it('sends the browser on to the web app, with the outcome in the fragment and not in the query', function () {
        $driver = User::factory()->guest()->create();
        $start = startProviderLink($driver, 'google')->json();
        providerSays('google');

        $response = comesBackFrom('google', stateOf($start['url']));
        $location = (string) $response->headers->get('Location');

        $response->assertRedirect();
        expect($location)->toStartWith('https://web.example/auth/callback#')
            ->and(parse_url($location, PHP_URL_QUERY))->toBeNull();
    });

    it('needs no device token, because it is the provider that sends the browser', function () {
        $start = startProviderLogin('facebook')->json();
        providerSays('facebook', ['id' => 'fb-1']);

        $fields = fragmentOf(comesBackFrom('facebook', stateOf($start['url'])));

        expect($fields['result'])->toBe('ready');
    });

    it('keeps a sign-in for no account until the browser finishes it', function () {
        $start = startProviderLogin('google')->json();
        providerSays('google', ['id' => '1098765']);

        comesBackFrom('google', stateOf($start['url']));
        $ticket = AccountToken::query()->where('purpose', 'oauth_result')->firstOrFail();

        expect($ticket->intent)->toBe('login')
            ->and($ticket->user_id)->toBeNull()
            ->and($ticket->consent_at)->toBeNull()
            ->and(User::query()->count())->toBe(0);
    });

    it('takes the form post of Apple, which may not tell the e-mail address', function () {
        $driver = User::factory()->guest()->create();
        $start = startProviderLink($driver, 'apple')->json();
        providerSays('apple', ['id' => '001234.abc', 'email' => null]);

        $fields = fragmentOf(comesBackFrom('apple', stateOf($start['url']), method: 'post'));
        $ticket = AccountToken::query()->where('purpose', 'oauth_result')->firstOrFail();

        expect($fields['result'])->toBe('ready')
            ->and($fields['provider'])->toBe('apple')
            ->and($ticket->subject)->toBe('001234.abc')
            ->and($ticket->email)->toBeNull();
    });

    it('answers one return only, because the state is spent', function () {
        $driver = User::factory()->guest()->create();
        $start = startProviderLink($driver, 'google')->json();
        providerSays('google');

        $first = fragmentOf(comesBackFrom('google', stateOf($start['url'])));
        $second = fragmentOf(comesBackFrom('google', stateOf($start['url'])));

        expect($first['result'])->toBe('ready')
            ->and($second)->toBe(['result' => 'error', 'provider' => 'google'])
            ->and(AccountToken::query()->where('purpose', 'oauth_result')->count())->toBe(1);
    });

    it('does not take a state it never gave', function (string $state) {
        providerSays('google');

        expect(fragmentOf(comesBackFrom('google', $state)))->toBe(['result' => 'error', 'provider' => 'google'])
            ->and(AccountToken::query()->where('purpose', 'oauth_result')->count())->toBe(0);
    })->with([
        'a guess' => ['not-a-state'],
        'none' => [''],
    ]);

    it('does not take as a state a secret that is for something else', function () {
        $driver = User::factory()->guest()->create();
        $secret = (new AccountTokens)->issue(AccountToken::LOGIN, 600, ['user_id' => $driver->id]);
        providerSays('google');

        expect(fragmentOf(comesBackFrom('google', $secret))['result'])->toBe('error')
            ->and(AccountToken::query()->where('purpose', 'oauth_result')->count())->toBe(0);
    });

    it('does not take the state of one provider from another, and spends it', function () {
        $driver = User::factory()->guest()->create();
        $start = startProviderLink($driver, 'google')->json();
        providerSays('facebook');
        providerSays('google');

        $other = fragmentOf(comesBackFrom('facebook', stateOf($start['url'])));
        $own = fragmentOf(comesBackFrom('google', stateOf($start['url'])));

        expect($other['result'])->toBe('error')
            ->and($own['result'])->toBe('error')
            ->and(AccountToken::query()->where('purpose', 'oauth_result')->count())->toBe(0);
    });

    it('says the person said no, when the provider says so', function () {
        $driver = User::factory()->guest()->create();
        $start = startProviderLink($driver, 'google')->json();
        providerSays('google');

        $fields = fragmentOf(comesBackFrom('google', stateOf($start['url']), ['error' => 'access_denied']));

        expect($fields)->toBe(['result' => 'cancelled', 'provider' => 'google'])
            ->and(AccountToken::query()->where('purpose', 'oauth_result')->count())->toBe(0)
            // The state is spent, whatever the answer was.
            ->and(fragmentOf(comesBackFrom('google', stateOf($start['url'])))['result'])->toBe('error');
    });

    it('says it failed, and reports why, when the provider does not tell who the person is', function () {
        Exceptions::fake();
        $driver = User::factory()->guest()->create();
        $start = startProviderLink($driver, 'google')->json();
        Socialite::fake('google', fn () => throw new RuntimeException('the provider is down'));

        $fields = fragmentOf(comesBackFrom('google', stateOf($start['url'])));

        expect($fields)->toBe(['result' => 'error', 'provider' => 'google'])
            ->and(AccountToken::query()->where('purpose', 'oauth_result')->count())->toBe(0);
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'the provider is down');
    });

    it('does not take a person with no id', function (?string $id) {
        $driver = User::factory()->guest()->create();
        $start = startProviderLink($driver, 'google')->json();
        providerSays('google', ['id' => $id]);

        expect(fragmentOf(comesBackFrom('google', stateOf($start['url'])))['result'])->toBe('error')
            ->and(AccountToken::query()->where('purpose', 'oauth_result')->count())->toBe(0);
    })->with([
        'empty' => [''],
        'none' => [null],
    ]);

    it('does not ask a provider that has been turned off since the start', function () {
        $driver = User::factory()->guest()->create();
        $start = startProviderLink($driver, 'google')->json();
        providerSays('google');
        withProviders([]);

        expect(fragmentOf(comesBackFrom('google', stateOf($start['url'])))['result'])->toBe('error')
            ->and(AccountToken::query()->where('purpose', 'oauth_result')->count())->toBe(0);
    });

    it('does not know a provider that is not one of the three', function () {
        $this->get('/api/v1/auth/github/callback?state=x&code=y')->assertNotFound();
    });
});
