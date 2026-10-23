<?php

declare(strict_types=1);

use App\Models\AccountIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

function meAs(User $user): Illuminate\Testing\TestResponse
{
    return test()->getJson('/api/v1/me', bearerFor($user));
}

it('shows no identities for a guest', function () {
    meAs(User::factory()->guest()->create())->assertOk()->assertJsonPath('data.is_guest', true)->assertJsonPath('data.identities', []);
});

it('shows the ways the account is secured, the address masked', function () {
    $user = User::factory()->guest()->create();
    identityOf($user, AccountIdentity::EMAIL, 'turbo@example.com', 'turbo@example.com');
    identityOf($user, AccountIdentity::GOOGLE, '1098765', 'maciek.turbo@gmail.com');
    identityOf($user, AccountIdentity::APPLE, 'apple-subject');

    meAs($user)->assertOk()->assertJsonPath('data.identities', [
        ['provider' => 'email', 'email' => 't***@example.com'],
        ['provider' => 'google', 'email' => 'm***@gmail.com'],
        ['provider' => 'apple', 'email' => null],
    ]);
});

it('never shows the whole address, the subject or anything a provider reported besides the address', function () {
    $user = User::factory()->guest()->create();
    identityOf($user, AccountIdentity::GOOGLE, 'secret-subject-1098765', 'maciek.turbo@gmail.com');

    $body = meAs($user)->assertOk()->getContent();

    expect($body)->not->toContain('maciek.turbo@gmail.com')->not->toContain('secret-subject-1098765');
});

it('shows a secured account as not a guest, in the answer that secured it too', function () {
    Mail::fake();
    $guest = User::factory()->guest()->create(['claimed_at' => null]);

    confirmLink(claimSecretFor($guest))
        ->assertOk()
        ->assertJsonPath('data.is_guest', false)
        ->assertJsonPath('data.identities.0.provider', 'email')
        ->assertJsonPath('data.identities.0.email', 't***@example.com');
});

it('does not hand out the identities of another driver', function () {
    $other = User::factory()->guest()->create();
    identityOf($other, AccountIdentity::GOOGLE, '1', 'other@example.com');

    meAs(User::factory()->guest()->create())->assertOk()->assertJsonPath('data.identities', []);
});
