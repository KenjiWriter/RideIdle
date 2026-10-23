<?php

declare(strict_types=1);

use App\Mail\AccountLinkMail;
use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Models\User;
use App\Services\AccountTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());
afterEach(fn () => Carbon::setTestNow());

describe('a link that secures an account', function () {
    it('secures the guest with the address, and saves the consent', function () {
        $guest = User::factory()->guest()->create(['claimed_at' => null]);
        $secret = claimSecretFor($guest);

        confirmLink($secret)
            ->assertOk()
            ->assertJsonPath('purpose', 'claim')
            ->assertJsonPath('data.id', $guest->id)
            ->assertJsonPath('data.is_guest', false);

        $fresh = $guest->fresh();
        expect($fresh->isGuest())->toBeFalse()
            ->and($fresh->email)->toBe('turbo@example.com')
            ->and($fresh->email_verified_at)->not->toBeNull()
            ->and($fresh->terms_accepted_at)->not->toBeNull()
            ->and($fresh->age_confirmed_at)->not->toBeNull()
            ->and($fresh->terms_version)->toBe(config('ride.legal.version'))
            ->and($fresh->identities()->where('provider', 'email')->where('subject', 'turbo@example.com')->exists())->toBeTrue();
    });

    it('signs nobody in, because it may be opened in another browser than the one the guest plays in', function () {
        $guest = User::factory()->guest()->create();

        $answer = confirmLink(claimSecretFor($guest))->assertOk();

        expect($answer->json())->not->toHaveKey('token')
            // The token the test made for the guest to ask for the link is not a sign-in made by the link.
            ->and($guest->tokens()->where('name', 'email-login')->count())->toBe(0);
    });

    it('keeps the account from expiring as a guest', function () {
        $guest = User::factory()->guest()->create(['last_seen_at' => now()->subDays(200)]);
        confirmLink(claimSecretFor($guest))->assertOk();

        $this->artisan('accounts:prune-guests')->assertSuccessful();

        expect(User::query()->whereKey($guest->id)->exists())->toBeTrue();
    });

    it('is done by the link the guest was sent and by no other', function () {
        $guest = User::factory()->guest()->create();
        $other = User::factory()->guest()->create();
        $guestSecret = claimSecretFor($guest, 'one@example.com');
        claimSecretFor($other, 'two@example.com');

        confirmLink($guestSecret)->assertOk();

        expect($other->fresh()->isGuest())->toBeTrue()
            ->and($guest->fresh()->email)->toBe('one@example.com');
    });

    it('works once', function () {
        $secret = claimSecretFor(User::factory()->guest()->create());

        confirmLink($secret)->assertOk();
        confirmLink($secret)->assertUnprocessable()->assertJsonValidationErrors(['token']);
    });

    it('stops working after thirty minutes', function () {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $guest = User::factory()->guest()->create();
        $secret = claimSecretFor($guest);

        Carbon::setTestNow('2026-10-23 12:30:00');
        confirmLink($secret)->assertUnprocessable()->assertJsonValidationErrors(['token']);

        expect($guest->fresh()->isGuest())->toBeTrue();
    });

    it('is refused when somebody secured an account with the address meanwhile, and changes nothing', function () {
        $guest = User::factory()->guest()->create();
        $secret = claimSecretFor($guest);

        identityOf(User::factory()->guest()->create(), AccountIdentity::EMAIL, 'turbo@example.com');

        confirmLink($secret)->assertStatus(409)->assertJsonPath('code', 'identity_taken');

        expect($guest->fresh()->isGuest())->toBeTrue()
            ->and($guest->identities()->count())->toBe(0);
    });

    it('is refused when the account got an e-mail meanwhile', function () {
        $guest = User::factory()->guest()->create();
        $secret = claimSecretFor($guest);

        identityOf($guest, AccountIdentity::EMAIL, 'another@example.com');

        confirmLink($secret)->assertStatus(409)->assertJsonPath('code', 'provider_already_linked');
    });

    it('adds the e-mail to an account that is secured with a provider already', function () {
        $user = User::factory()->guest()->create();
        identityOf($user, AccountIdentity::GOOGLE, '1098765');

        confirmLink(claimSecretFor($user))->assertOk()->assertJsonPath('purpose', 'claim');

        expect($user->identities()->count())->toBe(2);
    });
});

describe('a link that signs a driver in', function () {
    function ownerWithAddress(): User
    {
        $owner = User::factory()->guest()->create(['name' => 'Stary_Kierowca']);
        identityOf($owner, AccountIdentity::EMAIL, 'turbo@example.com', 'turbo@example.com');

        return $owner;
    }

    it('signs the owner of the address in on this device, with a token that opens their account', function () {
        $owner = ownerWithAddress();
        $guest = User::factory()->guest()->create();

        // A guest asks to secure a new garage with an address that already has one: the owner gets the link.
        askForEmailLink($guest)->assertStatus(202);
        $secret = secretOf(Mail::queued(AccountLinkMail::class)->first());

        $answer = confirmLink($secret)
            ->assertOk()
            ->assertJsonPath('purpose', 'login')
            ->assertJsonPath('data.id', $owner->id);

        // The guards remember the user of an earlier request of this test.
        app('auth')->forgetGuards();
        test()->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$answer->json('token')])
            ->assertOk()
            ->assertJsonPath('data.name', 'Stary_Kierowca');

        expect($guest->fresh()->isGuest())->toBeTrue();
    });

    it('works once', function () {
        $owner = ownerWithAddress();
        $guest = User::factory()->guest()->create();
        askForEmailLink($guest)->assertStatus(202);
        $secret = secretOf(Mail::queued(AccountLinkMail::class)->first());

        confirmLink($secret)->assertOk();
        confirmLink($secret)->assertUnprocessable();

        expect($owner->tokens()->count())->toBe(1);
    });

    it('is refused when the account is gone', function () {
        $owner = ownerWithAddress();
        askForEmailLink(User::factory()->guest()->create())->assertStatus(202);
        $secret = secretOf(Mail::queued(AccountLinkMail::class)->first());

        $owner->delete();

        confirmLink($secret)->assertUnprocessable();
    });
});

describe('what is not a link', function () {
    it('refuses a secret that was never given, the same as one that was used', function (string $secret) {
        confirmLink($secret)->assertUnprocessable()->assertJsonValidationErrors(['token']);
    })->with([
        'a guess' => ['not-a-real-link'],
        'a very long one' => [str_repeat('a', 129)],
    ]);

    it('needs a token', function () {
        test()->postJson('/api/v1/account/email/confirm', [])->assertUnprocessable()->assertJsonValidationErrors(['token']);
    });

    it('does not take a secret of another kind for a link', function () {
        $secret = (new AccountTokens)->issue(AccountToken::RESULT, 600, ['user_id' => User::factory()->guest()->create()->id]);

        confirmLink($secret)->assertUnprocessable();
    });

    it('says so in the language of the request', function () {
        confirmLink('nope', ['X-Locale' => 'pl'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.token.0', 'Ten link jest nieprawidłowy albo wygasł.');
    });

    it('limits the number of attempts from one address', function () {
        $statuses = collect(range(1, 31))->map(fn () => confirmLink('nope')->getStatusCode());

        expect($statuses->take(30)->unique()->all())->toBe([422])
            ->and($statuses->last())->toBe(429);
    });
});
