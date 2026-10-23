<?php

declare(strict_types=1);

use App\Mail\AccountLinkMail;
use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Models\User;
use App\Services\AccountTokens;
use Illuminate\Support\Facades\Mail;

function askForLoginLink(string $email = 'turbo@example.com', array $headers = []): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/account/login-link', ['email' => $email], $headers);
}

function securedWithEmail(string $email = 'turbo@example.com', array $attributes = []): User
{
    $user = User::factory()->guest()->create(['name' => 'Maciek_Turbo', ...$attributes]);
    identityOf($user, AccountIdentity::EMAIL, $email, $email);

    return $user;
}

beforeEach(fn () => Mail::fake());

it('sends the owner of the address a link that signs them in', function () {
    $owner = securedWithEmail(attributes: ['locale' => 'pl']);

    askForLoginLink()
        ->assertStatus(202)
        ->assertJsonPath('expires_in', 1_800)
        ->assertJsonPath('message', 'If this e-mail belongs to a garage, a link is on its way. It works once, for 30 minutes.');

    Mail::assertQueued(AccountLinkMail::class, 1);
    Mail::assertQueued(AccountLinkMail::class, fn (AccountLinkMail $mail) => $mail->hasTo('turbo@example.com') && $mail->kind === 'login' && $mail->driver === 'Maciek_Turbo' && $mail->locale === 'pl');

    $token = (new AccountTokens())->consume(AccountToken::LOGIN, secretOf(Mail::queued(AccountLinkMail::class)->first()));
    expect($token->user_id)->toBe($owner->id)->and($token->email)->toBe('turbo@example.com');
});

it('signs the driver in on a new device when the link is used', function () {
    securedWithEmail();
    askForLoginLink()->assertStatus(202);
    $secret = secretOf(Mail::queued(AccountLinkMail::class)->first());

    $answer = confirmLink($secret)->assertOk()->assertJsonPath('purpose', 'login')->assertJsonPath('data.name', 'Maciek_Turbo');

    app('auth')->forgetGuards();
    test()->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$answer->json('token')])->assertOk()->assertJsonPath('data.name', 'Maciek_Turbo');
});

it('answers exactly the same for an address that has no garage, and sends nothing', function () {
    securedWithEmail('known@example.com');

    $known = askForLoginLink('known@example.com');
    Mail::fake();
    $unknown = askForLoginLink('nobody@example.com');

    expect($unknown->getStatusCode())->toBe($known->getStatusCode())
        ->and($unknown->json())->toBe($known->json());

    Mail::assertNothingQueued();
    expect(AccountToken::query()->where('email', 'nobody@example.com')->count())->toBe(0);
});

it('finds the address whatever the case and the spaces', function () {
    securedWithEmail();

    askForLoginLink('  TURBO@Example.com ')->assertStatus(202);

    Mail::assertQueued(AccountLinkMail::class, fn (AccountLinkMail $mail) => $mail->hasTo('turbo@example.com'));
});

it('sends nothing for an account that has no e-mail, only a provider', function () {
    $user = User::factory()->guest()->create();
    identityOf($user, AccountIdentity::GOOGLE, '1098765', 'turbo@example.com');

    askForLoginLink()->assertStatus(202);

    Mail::assertNothingQueued();
});

it('is open to anybody, and a token of another driver changes nothing', function () {
    securedWithEmail();
    $someone = User::factory()->guest()->create();

    test()->postJson('/api/v1/account/login-link', ['email' => 'turbo@example.com'], bearerFor($someone))->assertStatus(202);

    Mail::assertQueued(AccountLinkMail::class, fn (AccountLinkMail $mail) => $mail->kind === 'login');
});

it('needs a valid address', function (string $email) {
    askForLoginLink($email)->assertUnprocessable()->assertJsonValidationErrors(['email']);

    Mail::assertNothingQueued();
})->with(['empty' => [''], 'not an address' => ['turbo'], 'too long' => [str_repeat('a', 190).'@example.com']]);

it('says in the language of the request that a link is on its way', function () {
    askForLoginLink('anyone@example.com', ['X-Locale' => 'de'])
        ->assertStatus(202)
        ->assertJsonPath('message', 'Wenn diese E-Mail-Adresse zu einer Garage gehört, ist ein Link unterwegs. Er ist nur einmal und 30 Minuten lang gültig.');
});

describe('limits', function () {
    it('allows three links to the same address in an hour, and no more', function () {
        securedWithEmail();

        $statuses = collect(range(1, 4))->map(fn () => askForLoginLink()->getStatusCode());

        expect($statuses->all())->toBe([202, 202, 202, 429]);
    });

    it('limits an address that has no garage in the same way, so the limit does not give it away', function () {
        $statuses = collect(range(1, 4))->map(fn () => askForLoginLink('nobody@example.com')->getStatusCode());

        expect($statuses->all())->toBe([202, 202, 202, 429]);
    });

    it('allows ten requests an hour from one network address', function () {
        $statuses = collect(range(1, 11))->map(fn (int $n) => askForLoginLink("address{$n}@example.com")->getStatusCode());

        expect($statuses->take(10)->unique()->all())->toBe([202])->and($statuses->last())->toBe(429);
    });
});
