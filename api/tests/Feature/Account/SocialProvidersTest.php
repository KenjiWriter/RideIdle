<?php

declare(strict_types=1);

use App\Services\SocialProviders;
use Laravel\Socialite\Facades\Socialite;

describe('which providers are on', function () {
    it('has none until a provider has its keys', function () {
        withProviders([]);

        expect((new SocialProviders)->enabled())->toBe([]);
    });

    it('turns a provider on when it has all its keys, and lists them in the order they are offered', function () {
        withProviders(providerKeys());

        expect((new SocialProviders)->enabled())->toBe(['google', 'facebook', 'apple']);
    });

    it('offers only the providers that were set up', function () {
        withProviders(['facebook' => providerKeys()['facebook']]);

        expect((new SocialProviders)->enabled())->toBe(['facebook']);
    });

    it('does not offer a provider with half its keys', function (string $provider, array $keys) {
        withProviders([$provider => $keys]);

        expect((new SocialProviders)->isEnabled($provider))->toBeFalse();
    })->with([
        'google without a secret' => ['google', ['client_id' => 'id']],
        'google without an id' => ['google', ['client_secret' => 'secret']],
        'facebook with empty keys' => ['facebook', ['client_id' => '', 'client_secret' => '']],
        'apple with an id only' => ['apple', ['client_id' => 'com.example.ride']],
        'apple with a team but no key' => ['apple', ['client_id' => 'com.example.ride', 'team_id' => 'TEAM', 'key_id' => 'KEY']],
    ]);

    it('turns Apple on with a ready secret, or with the team, the key id and the private key', function () {
        withProviders(['apple' => ['client_id' => 'com.example.ride', 'client_secret' => 'ready']]);
        expect((new SocialProviders)->isEnabled('apple'))->toBeTrue();

        withProviders(['apple' => ['client_id' => 'com.example.ride', 'team_id' => 'TEAM', 'key_id' => 'KEY', 'private_key' => '/keys/AuthKey.p8']]);
        expect((new SocialProviders)->isEnabled('apple'))->toBeTrue();
    });

    it('does not know a provider that is not one of the three', function () {
        config(['services.github' => ['client_id' => 'id', 'client_secret' => 'secret']]);

        expect((new SocialProviders)->isEnabled('github'))->toBeFalse();
    });
});

describe('the list for a client', function () {
    it('is open to everybody, and empty when nothing is set up', function () {
        withProviders([]);

        $this->getJson('/api/v1/auth/providers')->assertOk()->assertExactJson(['data' => []]);
    });

    it('lists what is on', function () {
        withProviders(['google' => providerKeys()['google'], 'apple' => providerKeys()['apple']]);

        $this->getJson('/api/v1/auth/providers')->assertOk()->assertExactJson(['data' => ['google', 'apple']]);
    });
});

describe('the authorization address of the real providers', function () {
    // No network: only the address a browser would be sent to is built.
    beforeEach(fn () => withProviders(providerKeys()));

    it('carries our state, the key of the client and the address to return to', function (string $provider, string $host, string $clientId) {
        $url = Socialite::driver($provider)->stateless()->with(['state' => 'state-123'])->redirect()->getTargetUrl();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect(parse_url($url, PHP_URL_HOST))->toBe($host)
            ->and($query['state'])->toBe('state-123')
            ->and($query['client_id'])->toBe($clientId)
            ->and($query['redirect_uri'])->toBe("https://api.example/api/v1/auth/{$provider}/callback")
            ->and($query['response_type'])->toBe('code');
    })->with([
        'google' => ['google', 'accounts.google.com', 'google-id'],
        'facebook' => ['facebook', 'www.facebook.com', 'facebook-id'],
        'apple' => ['apple', 'appleid.apple.com', 'com.example.ride'],
    ]);

    it('has Apple return in a form post and carry the nonce we manage, which Apple checks in the identity token', function () {
        $url = Socialite::driver('apple')->stateless()->setNonce('nonce-456')->with(['state' => 'state-123'])->redirect()->getTargetUrl();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($query['response_mode'])->toBe('form_post')
            ->and($query['nonce'])->toBe('nonce-456')
            ->and($query['state'])->toBe('state-123');
    });
});
