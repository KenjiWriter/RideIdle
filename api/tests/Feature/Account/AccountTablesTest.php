<?php

declare(strict_types=1);

use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('has the tables and columns the account flows need', function () {
    expect(Schema::hasColumns('account_identities', ['user_id', 'provider', 'subject', 'email']))->toBeTrue()
        ->and(Schema::hasColumns('account_tokens', ['purpose', 'token_hash', 'user_id', 'provider', 'intent', 'email', 'consent_at', 'expires_at', 'used_at']))->toBeTrue()
        ->and(Schema::hasColumns('users', ['terms_accepted_at', 'age_confirmed_at']))->toBeTrue();
});

it('links an identity to an account and finds it from the account', function () {
    $user = User::factory()->guest()->create();

    identityOf($user, AccountIdentity::GOOGLE, '1098765', 'turbo@example.com');

    expect($user->identities)->toHaveCount(1)
        ->and($user->identities->first()->provider)->toBe('google')
        ->and($user->identities->first()->user->is($user))->toBeTrue();
});

it('lets one identity belong to one account only', function () {
    identityOf(User::factory()->guest()->create(), AccountIdentity::GOOGLE, '1098765');

    expect(fn () => identityOf(User::factory()->guest()->create(), AccountIdentity::GOOGLE, '1098765'))->toThrow(QueryException::class);
});

it('lets the same subject exist at different providers', function () {
    $first = User::factory()->guest()->create();
    $second = User::factory()->guest()->create();

    identityOf($first, AccountIdentity::GOOGLE, '42');
    identityOf($second, AccountIdentity::FACEBOOK, '42');

    expect(AccountIdentity::query()->count())->toBe(2);
});

it('lets an account have one identity of each provider and no more', function () {
    $user = User::factory()->guest()->create();

    identityOf($user, AccountIdentity::GOOGLE, '1');
    identityOf($user, AccountIdentity::APPLE, '2');
    identityOf($user, AccountIdentity::EMAIL, 'turbo@example.com');

    expect(fn () => identityOf($user, AccountIdentity::GOOGLE, '3'))->toThrow(QueryException::class)
        ->and($user->identities()->count())->toBe(3);
});

it('removes the identities and the tokens with the account', function () {
    $user = User::factory()->guest()->create();
    identityOf($user, AccountIdentity::GOOGLE, '1');
    tokenWith(['user_id' => $user->id]);

    $user->delete();

    expect(AccountIdentity::query()->count())->toBe(0)
        ->and(AccountToken::query()->count())->toBe(0);
});

it('keeps a token that belongs to no account yet, such as the state of a sign in', function () {
    $token = tokenWith(['purpose' => AccountToken::OAUTH, 'provider' => 'google', 'intent' => 'login']);

    expect($token->fresh()->user_id)->toBeNull()
        ->and($token->fresh()->expires_at->isFuture())->toBeTrue()
        ->and($token->fresh()->used_at)->toBeNull();
});

it('does not allow two tokens with the same hash', function () {
    tokenWith(['token_hash' => str_repeat('a', 64)]);

    expect(fn () => tokenWith(['token_hash' => str_repeat('a', 64)]))->toThrow(QueryException::class);
});

it('saves when a driver accepted the terms and confirmed their age', function () {
    $user = User::factory()->guest()->create();

    $user->forceFill(['terms_accepted_at' => now(), 'age_confirmed_at' => now()])->save();

    expect($user->fresh()->terms_accepted_at)->not->toBeNull()
        ->and($user->fresh()->age_confirmed_at)->not->toBeNull();
});
