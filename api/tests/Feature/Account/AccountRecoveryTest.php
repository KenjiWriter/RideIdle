<?php

declare(strict_types=1);

use App\Models\User;

/** @return array{0: string, 1: string} bearer token and recovery code of a new guest */
function guestWithCode(string $name = 'Kierowca'): array
{
    $token = test()->postJson('/api/v1/players', ['name' => $name])->json('token');
    $code = test()->postJson('/api/v1/account/recovery-code', [], ['Authorization' => "Bearer {$token}"])->json('code');

    return [$token, $code];
}

it('requires a token to issue a code', function () {
    $this->postJson('/api/v1/account/recovery-code')->assertUnauthorized();
});

it('issues a code once and stores only its hash', function () {
    [$token, $code] = guestWithCode();

    $user = User::query()->firstOrFail();

    expect($code)->toMatch('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/')
        ->and($user->recovery_code_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($user->recovery_code_hash)->not->toContain(str_replace('-', '', $code))
        ->and($user->recovery_code_set_at)->not->toBeNull();

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"])
        ->assertJsonPath('data.has_recovery_code', true)
        ->assertJsonMissingPath('data.recovery_code_hash');
});

it('signs the driver in on a new device with the code', function () {
    [, $code] = guestWithCode('Maciek_Turbo');

    $response = $this->postJson('/api/v1/account/recover', ['code' => strtolower($code)])
        ->assertOk()
        ->assertJsonPath('data.name', 'Maciek_Turbo');

    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$response->json('token')])
        ->assertOk()
        ->assertJsonPath('data.name', 'Maciek_Turbo');
});

it('accepts the code with spaces instead of dashes', function () {
    [, $code] = guestWithCode();

    $this->postJson('/api/v1/account/recover', ['code' => str_replace('-', ' ', $code)])->assertOk();
});

it('makes the previous code useless when a new one is issued', function () {
    [$token, $oldCode] = guestWithCode();

    $newCode = $this->postJson('/api/v1/account/recovery-code', [], ['Authorization' => "Bearer {$token}"])->json('code');

    expect($newCode)->not->toBe($oldCode);

    $this->postJson('/api/v1/account/recover', ['code' => $oldCode])->assertUnprocessable();
    $this->postJson('/api/v1/account/recover', ['code' => $newCode])->assertOk();
});

it('rejects an unknown code with a message in the language of the request', function () {
    $english = $this->postJson('/api/v1/account/recover', ['code' => 'ABCD-EFGH-JKLM'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code'])
        ->json('errors.code.0');

    $french = $this->postJson('/api/v1/account/recover', ['code' => 'ABCD-EFGH-JKLM'], ['X-Locale' => 'fr'])->json('errors.code.0');

    expect($english)->toBe('This recovery code is not valid.')
        ->and($french)->toBe('Ce code de récupération n’est pas valide.');
});

it('rejects input that is not shaped like a code without touching the database', function () {
    $this->postJson('/api/v1/account/recover', ['code' => 'not a code'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    $this->postJson('/api/v1/account/recover', [])->assertUnprocessable();
});

it('locks recovery for 24 hours after five failed attempts', function () {
    [, $code] = guestWithCode();

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/account/recover', ['code' => 'ABCD-EFGH-JKLM'])->assertUnprocessable();
    }

    // The hourly limit and the lock both refuse now, even with the right code.
    $this->postJson('/api/v1/account/recover', ['code' => $code])->assertTooManyRequests();

    // After the hourly window only the 24 hour lock remains.
    $this->travel(61)->minutes();
    $this->postJson('/api/v1/account/recover', ['code' => $code])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    $this->travel(25)->hours();
    $this->postJson('/api/v1/account/recover', ['code' => $code])->assertOk();
});

it('does not count successful recoveries as failures', function () {
    [, $code] = guestWithCode();

    foreach (range(1, 4) as $i) {
        $this->postJson('/api/v1/account/recover', ['code' => 'ABCD-EFGH-JKLM'])->assertUnprocessable();
    }

    $this->postJson('/api/v1/account/recover', ['code' => $code])->assertOk();
});

it('limits how often a code can be issued', function () {
    $token = $this->postJson('/api/v1/players', ['name' => 'Kierowca'])->json('token');

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/account/recovery-code', [], ['Authorization' => "Bearer {$token}"])->assertOk();
    }

    $this->postJson('/api/v1/account/recovery-code', [], ['Authorization' => "Bearer {$token}"])->assertTooManyRequests();
});
