<?php

declare(strict_types=1);

use App\Mail\AccountLinkMail;
use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Models\User;
use App\Services\AccountTokens;
use App\Support\Locale;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

it('requires a token', function () {
    $this->postJson('/api/v1/account/email', ['email' => 'turbo@example.com', 'accept_terms' => true, 'confirm_age' => true])->assertUnauthorized();
});

it('sends a link to confirm the e-mail of a guest', function () {
    $guest = User::factory()->guest()->create(['name' => 'Maciek_Turbo']);

    askForEmailLink($guest)
        ->assertStatus(202)
        ->assertJsonPath('message', 'We sent a link to turbo@example.com. It works once, for 30 minutes.')
        ->assertJsonPath('expires_in', 1_800);

    Mail::assertQueued(AccountLinkMail::class, 1);
    Mail::assertQueued(AccountLinkMail::class, function (AccountLinkMail $mail) {
        return $mail->hasTo('turbo@example.com')
            && $mail->kind === 'claim'
            && $mail->driver === 'Maciek_Turbo'
            && $mail->minutes === 30
            && str_starts_with($mail->url, config('ride.web_url').'/account/confirm?token=');
    });
});

it('makes a token for the guest that is good for the link and nothing else', function () {
    $guest = User::factory()->guest()->create();
    askForEmailLink($guest)->assertStatus(202);

    $mail = Mail::queued(AccountLinkMail::class)->first();
    $secret = secretOf($mail);

    // The secret is in the link only; the table holds its hash, and it is a claim for this guest and this address.
    expect(AccountToken::query()->where('token_hash', $secret)->exists())->toBeFalse();

    $token = (new AccountTokens)->consume(AccountToken::CLAIM, $secret);

    expect($token->user_id)->toBe($guest->id)
        ->and($token->email)->toBe('turbo@example.com')
        ->and($token->consent_at)->not->toBeNull()
        ->and($token->expires_at->isFuture())->toBeTrue();
});

it('changes nothing on the account until the link is used', function () {
    $guest = User::factory()->guest()->create();

    askForEmailLink($guest)->assertStatus(202);

    expect($guest->fresh()->isGuest())->toBeTrue()
        ->and($guest->identities()->count())->toBe(0)
        ->and($guest->fresh()->terms_accepted_at)->toBeNull();
});

it('treats the same address written differently as the same address', function () {
    $guest = User::factory()->guest()->create();

    askForEmailLink($guest, ['email' => '  Turbo@Example.COM '])->assertStatus(202);

    Mail::assertQueued(AccountLinkMail::class, fn (AccountLinkMail $mail) => $mail->hasTo('turbo@example.com'));
    expect(AccountToken::query()->firstOrFail()->email)->toBe('turbo@example.com');
});

it('writes in the language of the driver', function (string $locale, string $subject) {
    $guest = User::factory()->guest()->create(['locale' => $locale]);

    askForEmailLink($guest)->assertStatus(202);

    $mail = Mail::queued(AccountLinkMail::class)->first();
    expect($mail->locale)->toBe(Locale::toLaravel($locale));

    app()->setLocale(Locale::toLaravel($locale));
    expect($mail->envelope()->subject)->toBe($subject);
})->with([
    'polish' => ['pl', 'Potwierdź e-mail, żeby zabezpieczyć garaż'],
    'english' => ['en', 'Confirm your e-mail to secure your garage'],
    'french' => ['fr', 'Confirmez votre adresse e-mail pour sécuriser votre garage'],
    'german' => ['de', 'Bestätige deine E-Mail, um deine Garage zu sichern'],
    'brazilian portuguese' => ['pt-BR', 'Confirme seu e-mail para salvar sua garagem'],
]);

it('renders the e-mail in html and in text, with the name, the link and the time', function () {
    $mail = new AccountLinkMail('claim', 'https://ride.example/account/confirm?token=abc', 'Maciek_Turbo', 30);

    $html = $mail->render();

    expect($html)->toContain('Maciek_Turbo')
        ->and($html)->toContain('href="https://ride.example/account/confirm?token=abc"')
        ->and($html)->toContain('30 minutes');

    $text = view('mail.account-link-text', ['kind' => 'claim', 'url' => 'https://ride.example/x', 'driver' => 'Maciek_Turbo', 'minutes' => 30])->render();
    expect($text)->toContain('https://ride.example/x')->toContain('Maciek_Turbo');
});

it('does not let a name put markup into the e-mail', function () {
    $mail = new AccountLinkMail('claim', 'https://ride.example/x', '<script>alert(1)</script>', 30);

    expect($mail->render())->not->toContain('<script>alert(1)</script>');
});

describe('an address that already has a garage', function () {
    function ownerOfAddress(): User
    {
        $owner = User::factory()->guest()->create(['name' => 'Stary_Kierowca', 'locale' => 'pl']);
        identityOf($owner, AccountIdentity::EMAIL, 'turbo@example.com', 'turbo@example.com');

        return $owner;
    }

    it('sends the link to the owner for their garage, not for a new one', function () {
        $owner = ownerOfAddress();
        $guest = User::factory()->guest()->create();

        askForEmailLink($guest)->assertStatus(202);

        Mail::assertQueued(AccountLinkMail::class, fn (AccountLinkMail $mail) => $mail->kind === 'existing' && $mail->driver === 'Stary_Kierowca' && $mail->locale === 'pl');

        $token = (new AccountTokens)->consume(AccountToken::LOGIN, secretOf(Mail::queued(AccountLinkMail::class)->first()));
        expect($token->user_id)->toBe($owner->id);
        expect($guest->fresh()->isGuest())->toBeTrue();
    });

    it('answers exactly as for a free address, so nobody can find out who has an account', function () {
        $free = askForEmailLink(User::factory()->guest()->create(), ['email' => 'free@example.com']);

        ownerOfAddress();
        $taken = askForEmailLink(User::factory()->guest()->create());

        expect($taken->getStatusCode())->toBe($free->getStatusCode())
            ->and(array_keys($taken->json()))->toBe(array_keys($free->json()))
            ->and($taken->json('expires_in'))->toBe($free->json('expires_in'));
    });
});

it('refuses an account that already has an e-mail', function () {
    $guest = User::factory()->guest()->create();
    identityOf($guest, AccountIdentity::EMAIL, 'first@example.com');

    askForEmailLink($guest, ['email' => 'second@example.com'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'email_already_linked')
        ->assertJsonPath('message', 'This account already has an e-mail.');

    Mail::assertNothingQueued();
});

it('lets an account secured with a provider add an e-mail', function () {
    $user = User::factory()->guest()->create();
    identityOf($user, AccountIdentity::GOOGLE, '1098765');

    askForEmailLink($user)->assertStatus(202);

    Mail::assertQueued(AccountLinkMail::class, fn (AccountLinkMail $mail) => $mail->kind === 'claim');
});

describe('an account that accepted the terms and confirmed its age already', function () {
    /** An account secured with Google a while ago, which accepted the terms then. */
    function securedWithGoogle(array $attributes = []): User
    {
        $user = User::factory()->create(['claimed_at' => now()->subWeek(), 'email' => null, 'terms_accepted_at' => now()->subWeek(), 'age_confirmed_at' => now()->subWeek(), ...$attributes]);
        identityOf($user, AccountIdentity::GOOGLE, '1098765');

        return $user;
    }

    it('adds an e-mail without being asked for them again', function () {
        $user = securedWithGoogle();

        test()->postJson('/api/v1/account/email', ['email' => 'turbo@example.com'], bearerFor($user))->assertStatus(202);

        // A garage that is secured already is not asked to be secured: the e-mail says it adds one more way in.
        Mail::assertQueued(AccountLinkMail::class, fn (AccountLinkMail $mail) => $mail->kind === 'add');
    });

    it('gets an e-mail in its own language that says it adds the address', function (string $locale, string $subject) {
        $user = securedWithGoogle(['locale' => $locale]);

        test()->postJson('/api/v1/account/email', ['email' => 'turbo@example.com'], bearerFor($user))->assertStatus(202);

        $mail = Mail::queued(AccountLinkMail::class)->first();
        app()->setLocale(Locale::toLaravel($locale));
        expect($mail->kind)->toBe('add')
            ->and($mail->envelope()->subject)->toBe($subject)
            ->and($mail->render())->toContain($user->name)->toContain($mail->url);
    })->with([
        'polish' => ['pl', 'Potwierdź e-mail, żeby dodać go do garażu'],
        'english' => ['en', 'Confirm your e-mail to add it to your garage'],
        'french' => ['fr', 'Confirmez votre adresse e-mail pour l’ajouter à votre garage'],
        'german' => ['de', 'Bestätige deine E-Mail, um sie deiner Garage hinzuzufügen'],
        'brazilian portuguese' => ['pt-BR', 'Confirme seu e-mail para adicioná-lo à sua garagem'],
    ]);

    it('keeps the moments it accepted them when the e-mail is added', function () {
        $user = securedWithGoogle();
        $accepted = $user->terms_accepted_at;
        test()->postJson('/api/v1/account/email', ['email' => 'turbo@example.com'], bearerFor($user))->assertStatus(202);

        confirmLink(secretOf(Mail::queued(AccountLinkMail::class)->last()))->assertOk();

        $user->refresh();
        expect($user->terms_accepted_at->equalTo($accepted))->toBeTrue()
            ->and($user->age_confirmed_at->equalTo($accepted))->toBeTrue()
            ->and($user->email)->toBe('turbo@example.com')
            ->and($user->identities()->pluck('provider')->sort()->values()->all())->toBe(['email', 'google']);
    });

    it('is still asked when only one of the two is on record', function (array $attributes, string $missing) {
        $user = securedWithGoogle($attributes);

        test()->postJson('/api/v1/account/email', ['email' => 'turbo@example.com'], bearerFor($user))
            ->assertUnprocessable()->assertJsonValidationErrors([$missing]);

        Mail::assertNothingQueued();
    })->with([
        'the age is missing' => [['age_confirmed_at' => null], 'confirm_age'],
        'the terms are missing' => [['terms_accepted_at' => null], 'accept_terms'],
    ]);
});

it('needs a valid address and the terms and the age confirmed', function (array $body, array $invalid) {
    $guest = User::factory()->guest()->create();

    askForEmailLink($guest, $body)->assertUnprocessable()->assertJsonValidationErrors($invalid);

    Mail::assertNothingQueued();
    expect(AccountToken::query()->count())->toBe(0);
})->with([
    'no address' => [['email' => ''], ['email']],
    'not an address' => [['email' => 'turbo'], ['email']],
    'an address that is too long' => [['email' => str_repeat('a', 190).'@example.com'], ['email']],
    'terms not accepted' => [['accept_terms' => false], ['accept_terms']],
    'age not confirmed' => [['confirm_age' => false], ['confirm_age']],
    'neither' => [['accept_terms' => false, 'confirm_age' => false], ['accept_terms', 'confirm_age']],
]);

describe('limits', function () {
    it('allows three links to the same address in an hour, and no more', function () {
        $statuses = collect(range(1, 4))->map(fn () => askForEmailLink(User::factory()->guest()->create())->getStatusCode());

        expect($statuses->all())->toBe([202, 202, 202, 429]);
    });

    it('allows five links from one account in an hour, and no more', function () {
        $guest = User::factory()->guest()->create();

        $statuses = collect(range(1, 6))->map(fn (int $n) => askForEmailLink($guest, ['email' => "address{$n}@example.com"])->getStatusCode());

        expect($statuses->all())->toBe([202, 202, 202, 202, 202, 429]);
    });

    it('counts a differently written address as the same one', function () {
        $statuses = collect(['turbo@example.com', 'TURBO@example.com', ' Turbo@Example.com', 'turbo@example.com'])
            ->map(fn (string $email) => askForEmailLink(User::factory()->guest()->create(), ['email' => $email])->getStatusCode());

        expect($statuses->all())->toBe([202, 202, 202, 429]);
    });
});
