<?php

declare(strict_types=1);

use App\Models\User;

it('creates a guest driver and returns a device token', function () {
    $response = $this->postJson('/api/v1/players', ['name' => 'Maciek_Turbo'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Maciek_Turbo')
        ->assertJsonPath('data.is_guest', true)
        ->assertJsonPath('data.has_recovery_code', false)
        ->assertJsonStructure(['data' => ['id', 'name', 'locale', 'is_guest', 'has_recovery_code'], 'token']);

    $user = User::query()->firstOrFail();

    expect($user->email)->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->name_key)->toBe('maciek_turbo')
        ->and($user->last_seen_at)->not->toBeNull()
        ->and($response->json('token'))->toBeString()->not->toBeEmpty();
});

it('uses the language of the request when none is given', function (string $header, string $expected) {
    $this->postJson('/api/v1/players', ['name' => 'Kierowca'], ['Accept-Language' => $header])
        ->assertCreated()
        ->assertJsonPath('data.locale', $expected);
})->with([
    ['fr-FR,fr;q=0.9', 'fr'],
    ['pt-BR', 'pt-BR'],
    ['es-ES', 'en'],
]);

it('stores the language from the body and understands the br alias', function () {
    $this->postJson('/api/v1/players', ['name' => 'Piloto', 'locale' => 'br'])
        ->assertCreated()
        ->assertJsonPath('data.locale', 'pt-BR');
});

it('rejects an unsupported language', function () {
    $this->postJson('/api/v1/players', ['name' => 'Piloto', 'locale' => 'es'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['locale']);
});

it('validates the driver name', function (string $name) {
    $this->postJson('/api/v1/players', ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect(User::query()->count())->toBe(0);
})->with([
    'too short' => ['ab'],
    'too long' => [str_repeat('a', 17)],
    'space' => ['ab cd'],
    'dot' => ['ab.cd'],
    'emoji' => ['ab🚗cd'],
    'empty' => [''],
]);

it('accepts letters from every supported language', function (string $name) {
    $this->postJson('/api/v1/players', ['name' => $name])->assertCreated();
})->with(['Łukasz', 'François', 'Müller_1', 'João-Pedro']);

it('does not allow the same name in a different case', function () {
    $this->postJson('/api/v1/players', ['name' => 'Turbo'])->assertCreated();

    $this->postJson('/api/v1/players', ['name' => 'TURBO'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->postJson('/api/v1/players', ['name' => 'turbo'])->assertUnprocessable();
    expect(User::query()->count())->toBe(1);
});

it('says that a name is taken in the language of the request', function (array $headers, string $message) {
    $this->postJson('/api/v1/players', ['name' => 'Turbo'])->assertCreated();

    $this->postJson('/api/v1/players', ['name' => 'Turbo'], $headers)
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', $message);
})->with([
    'English' => [[], 'This driver name is already taken.'],
    'Polish' => [['X-Locale' => 'pl'], 'To imię kierowcy jest już zajęte.'],
    'French' => [['Accept-Language' => 'fr'], 'Ce nom de pilote est déjà pris.'],
    'German' => [['X-Locale' => 'de'], 'Dieser Fahrername ist bereits vergeben.'],
    'Brazilian Portuguese' => [['X-Locale' => 'br'], 'Este nome de piloto já está em uso.'],
]);

it('names the field in validation messages in the language of the request', function () {
    $english = $this->postJson('/api/v1/players', ['name' => 'ab'])->json('errors.name.0');
    $polish = $this->postJson('/api/v1/players', ['name' => 'ab'], ['X-Locale' => 'pl'])->json('errors.name.0');

    expect($english)->toContain('driver name')
        ->and($polish)->toContain('imię kierowcy');
});

it('limits guest creation per address', function () {
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/players', ['name' => "Driver{$i}"])->assertCreated();
    }

    $this->postJson('/api/v1/players', ['name' => 'Driver11'])->assertTooManyRequests();
});
