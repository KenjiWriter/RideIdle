<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;

it('stores a guest without e-mail or password', function () {
    $guest = User::factory()->guest()->create();

    expect($guest->email)->toBeNull()
        ->and($guest->password)->toBeNull()
        ->and($guest->isGuest())->toBeTrue()
        ->and($guest->hasRecoveryCode())->toBeFalse();
});

it('treats an account with claimed_at as secured', function () {
    expect(User::factory()->create()->isGuest())->toBeFalse();
});

it('builds a case-insensitive name key', function () {
    expect(User::nameKey('  Maciek_Turbo '))->toBe('maciek_turbo')
        ->and(User::nameKey('ŁUKASZ'))->toBe('łukasz');
});

it('rejects two drivers with the same name key', function () {
    User::factory()->guest()->create(['name' => 'Turbo', 'name_key' => 'turbo']);

    expect(fn () => User::factory()->guest()->create(['name' => 'TURBO', 'name_key' => 'turbo']))
        ->toThrow(QueryException::class);
});

it('never exposes the recovery code hash in JSON', function () {
    $user = User::factory()->guest()->create(['recovery_code_hash' => str_repeat('a', 64)]);

    expect($user->toArray())->not->toHaveKey('recovery_code_hash')
        ->and($user->toArray())->not->toHaveKey('password');
});
