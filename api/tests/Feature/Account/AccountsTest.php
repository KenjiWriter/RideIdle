<?php

declare(strict_types=1);

use App\Exceptions\IdentityTakenException;
use App\Exceptions\ProviderAlreadyLinkedException;
use App\Models\AccountIdentity;
use App\Models\User;
use App\Services\Accounts;
use Illuminate\Support\Facades\Event;

function accounts(): Accounts
{
    return new Accounts;
}

it('normalizes an e-mail, so the same address is the same identity', function () {
    expect(Accounts::normalizeEmail('  Turbo@Example.COM '))->toBe('turbo@example.com');
});

it('secures a guest account by linking its first identity', function () {
    $guest = User::factory()->guest()->create();
    expect($guest->isGuest())->toBeTrue();

    $identity = accounts()->link($guest, AccountIdentity::GOOGLE, '1098765', 'turbo@example.com');

    expect($identity->provider)->toBe('google')
        ->and($identity->subject)->toBe('1098765')
        ->and($identity->email)->toBe('turbo@example.com')
        ->and($guest->isGuest())->toBeFalse() // the model the caller holds is up to date too
        ->and($guest->fresh()->isGuest())->toBeFalse();
});

it('makes the e-mail of the account only for an e-mail identity, not for a provider', function () {
    $viaGoogle = User::factory()->guest()->create();
    accounts()->link($viaGoogle, AccountIdentity::GOOGLE, '1', 'turbo@example.com');

    $viaEmail = User::factory()->guest()->create();
    accounts()->link($viaEmail, AccountIdentity::EMAIL, 'turbo@example.com', 'turbo@example.com');

    expect($viaGoogle->fresh()->email)->toBeNull()
        ->and($viaGoogle->fresh()->email_verified_at)->toBeNull()
        ->and($viaEmail->fresh()->email)->toBe('turbo@example.com')
        ->and($viaEmail->fresh()->email_verified_at)->not->toBeNull();
});

it('saves when the driver accepted the terms and confirmed their age', function () {
    $guest = User::factory()->guest()->create();
    $consent = now()->subMinutes(5)->startOfSecond();

    accounts()->link($guest, AccountIdentity::FACEBOOK, '55', null, $consent);

    $fresh = $guest->fresh();
    expect($fresh->terms_accepted_at->equalTo($consent))->toBeTrue()
        ->and($fresh->age_confirmed_at->equalTo($consent))->toBeTrue();
});

it('saves which version of the terms the driver accepted', function () {
    config(['ride.legal.version' => '2026-10-23']);
    $guest = User::factory()->guest()->create();

    accounts()->link($guest, AccountIdentity::GOOGLE, '1', null, now());

    expect($guest->fresh()->terms_version)->toBe('2026-10-23');
});

it('keeps the version the driver accepted when the texts change and another identity is linked', function () {
    config(['ride.legal.version' => '2026-10-23']);
    $guest = User::factory()->guest()->create();
    accounts()->link($guest, AccountIdentity::GOOGLE, '1', null, now());

    config(['ride.legal.version' => '2027-03-01']);
    accounts()->link($guest, AccountIdentity::APPLE, '2', null, now());

    expect($guest->fresh()->terms_version)->toBe('2026-10-23');
});

it('saves no version when no consent was given', function () {
    $user = User::factory()->guest()->create();

    accounts()->link($user, AccountIdentity::GOOGLE, '1');

    expect($user->fresh()->terms_version)->toBeNull();
});

it('keeps the first consent and the time of securing when another identity is linked', function () {
    $guest = User::factory()->guest()->create();
    $first = now()->subDay()->startOfSecond();
    accounts()->link($guest, AccountIdentity::GOOGLE, '1', null, $first);
    $claimedAt = $guest->fresh()->claimed_at;

    $this->travel(1)->hours();
    accounts()->link($guest, AccountIdentity::APPLE, '2', null, now());

    $fresh = $guest->fresh();
    expect($fresh->claimed_at->equalTo($claimedAt))->toBeTrue()
        ->and($fresh->terms_accepted_at->equalTo($first))->toBeTrue()
        ->and($fresh->identities)->toHaveCount(2);
});

it('does nothing when the same identity is linked again', function () {
    $user = User::factory()->guest()->create();
    $first = accounts()->link($user, AccountIdentity::GOOGLE, '1');

    $again = accounts()->link($user, AccountIdentity::GOOGLE, '1');

    expect($again->is($first))->toBeTrue()
        ->and(AccountIdentity::query()->count())->toBe(1);
});

it('refuses an identity that belongs to another account, and changes nothing', function () {
    $owner = User::factory()->guest()->create();
    accounts()->link($owner, AccountIdentity::GOOGLE, '1');
    $guest = User::factory()->guest()->create();

    expect(fn () => accounts()->link($guest, AccountIdentity::GOOGLE, '1'))->toThrow(IdentityTakenException::class);

    expect($guest->fresh()->isGuest())->toBeTrue()
        ->and($guest->identities()->count())->toBe(0)
        ->and(AccountIdentity::query()->count())->toBe(1);
});

it('refuses a second identity of the same provider', function () {
    $user = User::factory()->guest()->create();
    accounts()->link($user, AccountIdentity::GOOGLE, '1');

    expect(fn () => accounts()->link($user, AccountIdentity::GOOGLE, '2'))->toThrow(ProviderAlreadyLinkedException::class);

    expect($user->identities()->count())->toBe(1);
});

it('refuses an e-mail that is already the e-mail of another account', function () {
    User::factory()->create(['email' => 'turbo@example.com']);
    $guest = User::factory()->guest()->create();

    expect(fn () => accounts()->link($guest, AccountIdentity::EMAIL, 'turbo@example.com', 'turbo@example.com'))->toThrow(IdentityTakenException::class);

    expect($guest->fresh()->isGuest())->toBeTrue()
        ->and($guest->identities()->count())->toBe(0);
});

it('finds the account an identity belongs to', function () {
    $user = User::factory()->guest()->create();
    accounts()->link($user, AccountIdentity::APPLE, 'apple-subject');

    expect(accounts()->userFor(AccountIdentity::APPLE, 'apple-subject')->is($user))->toBeTrue()
        ->and(accounts()->userFor(AccountIdentity::APPLE, 'someone-else'))->toBeNull()
        ->and(accounts()->userFor(AccountIdentity::GOOGLE, 'apple-subject'))->toBeNull();
});

it('undoes everything when the account cannot be saved', function () {
    $guest = User::factory()->guest()->create();

    Event::listen('eloquent.updating: '.User::class, static function (): never {
        throw new RuntimeException('The disk is full.');
    });

    try {
        expect(fn () => accounts()->link($guest, AccountIdentity::GOOGLE, '1'))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.updating: '.User::class);
    }

    expect(AccountIdentity::query()->count())->toBe(0)
        ->and($guest->fresh()->isGuest())->toBeTrue();
});
