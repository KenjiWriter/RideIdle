<?php

declare(strict_types=1);

use App\Models\User;

function guestToken(string $name = 'Kierowca'): string
{
    return test()->postJson('/api/v1/players', ['name' => $name])->json('token');
}

it('requires a token', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('returns the profile of the device token owner', function () {
    $token = guestToken('Maciek_Turbo');

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('data.name', 'Maciek_Turbo')
        ->assertJsonPath('data.is_guest', true);
});

it('saves the account language and normalizes aliases', function () {
    $token = guestToken();

    $this->patchJson('/api/v1/me/locale', ['locale' => 'br'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('data.locale', 'pt-BR');

    expect(User::query()->firstOrFail()->locale)->toBe('pt-BR');
});

it('rejects an unsupported language', function () {
    $token = guestToken();

    $this->patchJson('/api/v1/me/locale', ['locale' => 'es'], ['Authorization' => "Bearer {$token}"])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['locale']);
});
