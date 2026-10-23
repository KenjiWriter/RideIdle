<?php

declare(strict_types=1);

use App\Models\AccountIdentity;
use App\Models\User;
use App\Services\AccountLookup;

function lookup(): AccountLookup
{
    return new AccountLookup;
}

describe('by id', function () {
    it('finds the account with that id', function () {
        $user = User::factory()->create();
        User::factory()->create();

        expect(lookup()->byId($user->id)->pluck('id')->all())->toBe([$user->id]);
    });

    it('finds nothing for an id that is not there', function () {
        expect(lookup()->byId(999_999))->toBeEmpty();
    });
});

describe('by driver name', function () {
    it('finds the account, whatever the case and the spaces around the name', function () {
        $user = User::factory()->guest()->create(['name' => 'Maciek_Turbo', 'name_key' => 'maciek_turbo']);

        expect(lookup()->byName('  MACIEK_turbo ')->pluck('id')->all())->toBe([$user->id]);
    });

    it('does not take part of a name for the name', function () {
        User::factory()->guest()->create(['name' => 'Maciek_Turbo', 'name_key' => 'maciek_turbo']);

        expect(lookup()->byName('Maciek'))->toBeEmpty();
    });
});

describe('by e-mail address', function () {
    it('finds the account that has the address as its e-mail', function () {
        $user = User::factory()->create(['email' => 'turbo@example.com']);

        expect(lookup()->byEmail('turbo@example.com')->pluck('id')->all())->toBe([$user->id]);
    });

    it('finds the account of an e-mail sign-in', function () {
        $user = User::factory()->guest()->create();
        identityOf($user, AccountIdentity::EMAIL, 'turbo@example.com', 'turbo@example.com');

        expect(lookup()->byEmail('turbo@example.com')->pluck('id')->all())->toBe([$user->id]);
    });

    it('finds the account that signs in with a provider that reported the address', function () {
        $user = User::factory()->guest()->create();
        identityOf($user, AccountIdentity::GOOGLE, '1098765', 'turbo@example.com');

        expect(lookup()->byEmail('turbo@example.com')->pluck('id')->all())->toBe([$user->id]);
    });

    it('does not mind how the address is written', function () {
        $user = User::factory()->create(['email' => 'turbo@example.com']);

        expect(lookup()->byEmail('  Turbo@Example.COM ')->pluck('id')->all())->toBe([$user->id]);
    });

    it('gives every account the address leads to, once each, and in order', function () {
        $viaEmail = User::factory()->create(['email' => 'turbo@example.com']);
        identityOf($viaEmail, AccountIdentity::EMAIL, 'turbo@example.com', 'turbo@example.com');
        $viaGoogle = User::factory()->guest()->create();
        identityOf($viaGoogle, AccountIdentity::GOOGLE, '1098765', 'turbo@example.com');
        $viaFacebook = User::factory()->guest()->create();
        identityOf($viaFacebook, AccountIdentity::FACEBOOK, 'fb-1', 'turbo@example.com');
        User::factory()->create(['email' => 'someone@example.com']);

        expect(lookup()->byEmail('turbo@example.com')->pluck('id')->all())->toBe([$viaEmail->id, $viaGoogle->id, $viaFacebook->id]);
    });

    it('compares the whole address, not a part of it', function () {
        User::factory()->create(['email' => 'turbo@example.com']);
        $other = User::factory()->guest()->create();
        identityOf($other, AccountIdentity::GOOGLE, '1', 'xturbo@example.com');

        expect(lookup()->byEmail('turbo@example.com'))->toHaveCount(1)
            ->and(lookup()->byEmail('turbo'))->toBeEmpty()
            ->and(lookup()->byEmail('%'))->toBeEmpty();
    });

    it('finds nothing for an address nobody has, or for none', function () {
        User::factory()->create(['email' => 'turbo@example.com']);

        expect(lookup()->byEmail('nobody@example.com'))->toBeEmpty()
            ->and(lookup()->byEmail(''))->toBeEmpty()
            ->and(lookup()->byEmail('   '))->toBeEmpty();
    });

    it('does not take the subject of another provider for an address', function () {
        $user = User::factory()->guest()->create();
        identityOf($user, AccountIdentity::GOOGLE, 'turbo@example.com');

        expect(lookup()->byEmail('turbo@example.com'))->toBeEmpty();
    });
});
