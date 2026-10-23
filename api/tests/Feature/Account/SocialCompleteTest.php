<?php

declare(strict_types=1);

use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Models\User;
use App\Services\AccountTokens;
use Illuminate\Support\Carbon;

beforeEach(fn () => withProviders(providerKeys()));

afterEach(fn () => Carbon::setTestNow());

describe('finishing a link', function () {
    it('links the provider to the account that started, which secures the guest', function () {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $driver = User::factory()->guest()->create();
        $held = providerTicket('google', $driver, ['id' => '1098765', 'email' => 'Turbo@Example.com']);

        $response = finishWith($held, $driver)->assertOk();

        $identity = AccountIdentity::query()->firstOrFail();
        $driver->refresh();

        expect($response->json('purpose'))->toBe('link')
            ->and($response->json('data.is_guest'))->toBeFalse()
            ->and($response->json('data.identities'))->toBe([['provider' => 'google', 'email' => 't***@example.com']])
            ->and($response->json())->not->toHaveKey('token')
            ->and($identity->user_id)->toBe($driver->id)
            ->and($identity->provider)->toBe('google')
            ->and($identity->subject)->toBe('1098765')
            ->and($identity->email)->toBe('turbo@example.com')
            ->and($driver->claimed_at)->not->toBeNull()
            ->and($driver->terms_accepted_at->equalTo(now()))->toBeTrue()
            ->and($driver->age_confirmed_at->equalTo(now()))->toBeTrue()
            // What a provider says about an e-mail address is not proof enough to make it the e-mail of the account.
            ->and($driver->email)->toBeNull()
            ->and($driver->tokens()->where('name', 'google-login')->count())->toBe(0);
    });

    it('can add a provider to an account that has an e-mail already', function () {
        $driver = User::factory()->create(['email' => 'turbo@example.com', 'claimed_at' => now()]);
        identityOf($driver, 'email', 'turbo@example.com', 'turbo@example.com');

        $response = finishWith(providerTicket('facebook', $driver, ['id' => 'fb-1', 'email' => null]), $driver)->assertOk();

        expect(collect($response->json('data.identities'))->pluck('provider')->all())->toBe(['email', 'facebook']);
    });

    it('needs the driver to be signed in', function () {
        $driver = User::factory()->guest()->create();
        $held = providerTicket('google', $driver);

        finishWith($held)->assertUnauthorized();

        expect(AccountIdentity::query()->count())->toBe(0);
    });

    it('is refused for another driver than the one that started', function () {
        $driver = User::factory()->guest()->create();
        $other = User::factory()->guest()->create();
        $held = providerTicket('google', $driver);

        finishWith($held, $other)->assertForbidden()->assertJsonPath('code', 'wrong_account');

        expect(AccountIdentity::query()->count())->toBe(0)
            ->and($other->fresh()->claimed_at)->toBeNull()
            // The ticket is spent, so it cannot be tried again from the right account.
            ->and(finishWith($held, $driver)->status())->toBe(422);
    });

    it('does not take a provider that belongs to another garage, and merges nothing', function () {
        $owner = User::factory()->create(['claimed_at' => now()]);
        identityOf($owner, 'google', '1098765', 'owner@example.com');
        $guest = User::factory()->guest()->create();

        finishWith(providerTicket('google', $guest, ['id' => '1098765']), $guest)
            ->assertConflict()->assertJsonPath('code', 'identity_taken');

        expect($guest->fresh()->claimed_at)->toBeNull()
            ->and($guest->identities()->count())->toBe(0)
            ->and($owner->identities()->count())->toBe(1);
    });

    it('does not take a second provider of a kind the account got in the meantime', function () {
        $driver = User::factory()->guest()->create();
        $held = providerTicket('google', $driver, ['id' => '1098765']);
        identityOf($driver, 'google', 'another-google-account');

        finishWith($held, $driver)->assertConflict()->assertJsonPath('code', 'provider_already_linked');

        expect($driver->identities()->count())->toBe(1);
    });

    it('links the same provider again without changing anything', function () {
        $driver = User::factory()->guest()->create();

        finishWith(providerTicket('google', $driver, ['id' => '1098765']), $driver)->assertOk();
        $before = $driver->fresh()->claimed_at;

        // The same Google account, once more: the flow itself refuses to start it, so the ticket is made by hand.
        $held = ['ticket' => 'handmade-ticket', 'verifier' => 'handmade-verifier'];
        AccountToken::query()->create([
            'purpose' => AccountToken::RESULT,
            'token_hash' => AccountTokens::hash($held['ticket']),
            'user_id' => $driver->id,
            'provider' => 'google',
            'intent' => 'link',
            'subject' => '1098765',
            'challenge' => AccountTokens::hash($held['verifier']),
            'expires_at' => now()->addMinutes(5),
        ]);

        finishWith($held, $driver)->assertOk();

        expect($driver->identities()->count())->toBe(1)
            ->and($driver->fresh()->claimed_at->equalTo($before))->toBeTrue();
    });
});

describe('finishing a sign-in', function () {
    it('gives a token for this device to the account the provider is linked to', function () {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $owner = User::factory()->create(['claimed_at' => now(), 'last_seen_at' => now()->subDays(3)]);
        identityOf($owner, 'google', '1098765', 'turbo@example.com');
        $held = providerTicket('google', null, ['id' => '1098765']);

        $response = finishWith($held)->assertOk();

        expect($response->json('purpose'))->toBe('login')
            ->and($response->json('data.id'))->toBe($owner->id)
            ->and($response->json('token'))->toBeString()
            ->and($owner->tokens()->where('name', 'google-login')->count())->toBe(1)
            ->and($owner->fresh()->last_seen_at->equalTo(now()))->toBeTrue();

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$response->json('token')])->assertOk()->assertJsonPath('data.id', $owner->id);
    });

    it('does not create an account for a provider that is linked to none', function () {
        $held = providerTicket('google', null, ['id' => '1098765', 'email' => 'new@example.com']);

        finishWith($held)->assertNotFound()->assertJsonPath('code', 'no_account');

        expect(User::query()->count())->toBe(0)
            ->and(AccountIdentity::query()->count())->toBe(0)
            ->and(AccountToken::query()->where('purpose', 'oauth_result')->whereNull('used_at')->count())->toBe(0);
    });

    it('does not find an account by the e-mail the provider reports', function () {
        $owner = User::factory()->create(['email' => 'turbo@example.com', 'claimed_at' => now()]);
        identityOf($owner, 'email', 'turbo@example.com', 'turbo@example.com');

        // Somebody else's Google account that says it has the e-mail of the owner: not enough to get in.
        $held = providerTicket('google', null, ['id' => 'another-person', 'email' => 'turbo@example.com']);

        finishWith($held)->assertNotFound()->assertJsonPath('code', 'no_account');

        expect($owner->tokens()->count())->toBe(0);
    });

    it('does not take the id of one provider for the id of another', function () {
        $owner = User::factory()->create(['claimed_at' => now()]);
        identityOf($owner, 'facebook', '1098765');

        finishWith(providerTicket('google', null, ['id' => '1098765']))->assertNotFound();
    });

    it('does not link anything for a driver who is signed in while the flow was for signing in', function () {
        $driver = User::factory()->guest()->create();
        $held = providerTicket('google', null, ['id' => '1098765']);

        finishWith($held, $driver)->assertNotFound()->assertJsonPath('code', 'no_account');

        expect($driver->identities()->count())->toBe(0)
            ->and($driver->fresh()->claimed_at)->toBeNull();
    });

    it('gives the garage back to the owner, whatever the language of the message', function () {
        $held = providerTicket('apple', null, ['id' => '001234.abc']);

        $this->withHeaders(['Accept-Language' => 'pl'])->postJson('/api/v1/auth/complete', $held)
            ->assertNotFound()
            ->assertJsonPath('message', 'Żaden garaż nie jest połączony z tym kontem Apple. Zacznij grać jako gość i połącz konto w swoim garażu.');
    });
});

describe('the ticket and the verifier', function () {
    it('works once', function () {
        $owner = User::factory()->create(['claimed_at' => now()]);
        identityOf($owner, 'google', '1098765');
        $held = providerTicket('google', null, ['id' => '1098765']);

        finishWith($held)->assertOk();
        finishWith($held)->assertUnprocessable()->assertJsonValidationErrors(['ticket']);

        expect($owner->tokens()->where('name', 'google-login')->count())->toBe(1);
    });

    it('does not work with the verifier of another flow, which is what stops a login CSRF', function () {
        // The attacker starts a flow and makes the victim finish it at the provider: the ticket is the attacker's, the victim's
        // browser holds only the verifier of a flow of its own.
        $attacker = User::factory()->guest()->create();
        $victim = User::factory()->guest()->create();
        $attackers = providerTicket('google', $attacker, ['id' => 'victims-google-account']);
        $victims = startProviderLink($victim, 'google')->json();

        finishWith(['ticket' => $attackers['ticket'], 'verifier' => $victims['verifier']], $victim)->assertUnprocessable();

        expect(AccountIdentity::query()->count())->toBe(0)
            // A wrong verifier does not spend the ticket: the attacker's own browser can still finish its flow.
            ->and(finishWith($attackers, $attacker)->status())->toBe(200);
    });

    it('does not work without both of them', function (array $change) {
        $driver = User::factory()->guest()->create();
        $held = providerTicket('google', $driver);

        finishWith($held, $driver, $change)->assertUnprocessable();

        expect(AccountIdentity::query()->count())->toBe(0);
    })->with([
        'no verifier' => [['verifier' => null]],
        'an empty verifier' => [['verifier' => '']],
        'no ticket' => [['ticket' => null]],
    ]);

    it('does not take a ticket it never gave, or the state of a flow in its place', function () {
        $driver = User::factory()->guest()->create();
        $start = startProviderLink($driver, 'google')->json();

        finishWith(['ticket' => 'not-a-ticket', 'verifier' => $start['verifier']], $driver)->assertUnprocessable();
        finishWith(['ticket' => stateOf($start['url']), 'verifier' => $start['verifier']], $driver)->assertUnprocessable();

        expect(AccountIdentity::query()->count())->toBe(0);
    });

    it('stops working after a few minutes', function () {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $driver = User::factory()->guest()->create();
        $held = providerTicket('google', $driver);

        Carbon::setTestNow('2026-10-23 12:05:01');
        finishWith($held, $driver)->assertUnprocessable();

        expect(AccountIdentity::query()->count())->toBe(0);
    });

    it('is limited for one network address', function () {
        foreach (range(1, 30) as $ignored) {
            finishWith(['ticket' => 'x', 'verifier' => 'y'])->assertUnprocessable();
        }

        finishWith(['ticket' => 'x', 'verifier' => 'y'])->assertTooManyRequests();
    });
});
