<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

describe('a copy of my data', function () {
    it('is for a driver with a device token only', function () {
        $this->getJson('/api/v1/account/export')->assertUnauthorized();
    });

    it('is everything the game holds about the account of the driver', function () {
        $user = accountWithHistory();

        $response = $this->getJson('/api/v1/account/export', bearerFor($user))->assertOk();

        expect($response->json('account.id'))->toBe($user->id)
            ->and($response->json('account.name'))->toBe('Maciek_Turbo')
            ->and($response->json('identities'))->toHaveCount(2)
            ->and($response->json('game.runs'))->toHaveCount(1)
            ->and($response->json('game.ledger'))->toHaveCount(1)
            ->and($response->json())->toHaveKeys(['exported_at', 'account', 'identities', 'devices', 'pending_links', 'game']);
    });

    it('is a guest\'s as well, who has little in it', function () {
        $guest = User::factory()->guest()->create(['name' => 'Gosc_Test']);

        $response = $this->getJson('/api/v1/account/export', bearerFor($guest))->assertOk();

        expect($response->json('account.name'))->toBe('Gosc_Test')
            ->and($response->json('account.secured_at'))->toBeNull()
            ->and($response->json('identities'))->toBe([]);
    });

    it('is about this account and never another', function () {
        $user = accountWithHistory();
        $other = User::factory()->create(['name' => 'Somebody_Else', 'email' => 'other@example.com']);
        aRun(['user_id' => $other->id]);

        $body = $this->getJson('/api/v1/account/export', bearerFor($user))->assertOk()->getContent();

        expect($body)->not->toContain('Somebody_Else')->not->toContain('other@example.com');
    });

    it('holds no secret', function () {
        $user = accountWithHistory();
        $user->forceFill(['password' => 'a-password-of-the-driver'])->save();
        $headers = bearerFor($user->refresh());

        $body = $this->getJson('/api/v1/account/export', $headers)->assertOk()->getContent();

        expect($body)->not->toContain($user->getAuthPassword())->not->toContain(str_repeat('a', 64))->not->toContain(PersonalAccessToken::query()->latest('id')->firstOrFail()->token);
    });

    it('is never kept by a cache, and is a file when it is opened', function () {
        $user = accountWithHistory();

        $response = $this->getJson('/api/v1/account/export', bearerFor($user))->assertOk();

        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="ride-idle-data-'.$user->id.'.json"');
    });

    it('keeps the letters of a name and the slashes of an address as they are', function () {
        $user = User::factory()->create(['name' => 'Zażółć_Gęślą', 'email' => 'a/b@example.com']);

        $body = $this->getJson('/api/v1/account/export', bearerFor($user))->assertOk()->getContent();

        expect($body)->toContain('Zażółć_Gęślą')->toContain('a/b@example.com');
    });

    it('changes nothing on the account', function () {
        $user = accountWithHistory();
        $before = [User::query()->count(), $user->identities()->count(), $user->raceRuns()->count()];

        $this->getJson('/api/v1/account/export', bearerFor($user))->assertOk();

        expect([User::query()->count(), $user->identities()->count(), $user->raceRuns()->count()])->toBe($before);
    });

    it('is limited to ten an hour for an account', function () {
        $user = User::factory()->guest()->create();
        $headers = bearerFor($user);

        $statuses = collect(range(1, 11))->map(fn () => $this->getJson('/api/v1/account/export', $headers)->getStatusCode());

        expect($statuses->all())->toBe([...array_fill(0, 10, 200), 429]);
    });
});

describe('deleting my account', function () {
    /** The request the app makes: the driver writes their name to say they mean it. */
    function deleteAccount(User $user, array $body, array $headers = []): TestResponse
    {
        return test()->deleteJson('/api/v1/account', $body, [...bearerFor($user), ...$headers]);
    }

    it('is for a driver with a device token only', function () {
        $this->deleteJson('/api/v1/account', ['confirm_name' => 'Maciek_Turbo'])->assertUnauthorized();
    });

    it('deletes the account and everything about it when the driver writes their name', function () {
        $user = accountWithHistory();

        deleteAccount($user, ['confirm_name' => 'Maciek_Turbo'])->assertOk()->assertExactJson(['deleted' => true]);

        expect(rowsOf())->toBe(['users' => 0, 'identities' => 0, 'devices' => 0, 'links' => 0, 'wallets' => 0, 'runs' => 0, 'settlements' => 0, 'events' => 0, 'ledger' => 0]);
    });

    it('does not mind the case of the name or the spaces around it', function () {
        $user = accountWithHistory();

        deleteAccount($user, ['confirm_name' => '  maciek_TURBO '])->assertOk();

        expect(User::query()->find($user->id))->toBeNull();
    });

    it('signs the device out: its token does not work any more', function () {
        $user = accountWithHistory();
        $headers = bearerFor($user);

        $this->deleteJson('/api/v1/account', ['confirm_name' => 'Maciek_Turbo'], $headers)->assertOk();

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/account/export', $headers)->assertUnauthorized();
    });

    it('deletes a guest as well', function () {
        $guest = User::factory()->guest()->create(['name' => 'Gosc_Test']);

        deleteAccount($guest, ['confirm_name' => 'Gosc_Test'])->assertOk();

        expect(User::query()->count())->toBe(0);
    });

    it('is refused for another name, and removes nothing', function (array $body) {
        $user = accountWithHistory();
        User::factory()->create(['name' => 'Somebody_Else']);
        // The device token is made first: it is a row of its own, and the count is of what the refusal leaves.
        $headers = bearerFor($user);
        $before = rowsOf();

        $this->deleteJson('/api/v1/account', $body, $headers)->assertUnprocessable()->assertJsonValidationErrors(['confirm_name']);

        expect(rowsOf())->toBe($before)->and(User::query()->find($user->id))->not->toBeNull();
    })->with([
        'a wrong name' => [['confirm_name' => 'Maciek']],
        'the name of another driver' => [['confirm_name' => 'Somebody_Else']],
        'nothing' => [[]],
        'an empty name' => [['confirm_name' => '']],
        'a number' => [['confirm_name' => 12]],
        'an array' => [['confirm_name' => ['Maciek_Turbo']]],
    ]);

    it('says in the language of the request what is wanted', function () {
        $user = accountWithHistory();

        deleteAccount($user, ['confirm_name' => 'nope'], ['X-Locale' => 'pl'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.confirm_name.0', 'Wpisz imię kierowcy dokładnie tak, jak jest zapisane, żeby usunąć konto.');
    });

    it('leaves another account and everything of it as it was', function () {
        $user = accountWithHistory();
        $other = User::factory()->create(['name' => 'Somebody_Else', 'email' => 'other@example.com']);
        identityOf($other, 'google', 'other-google', 'other@example.com');
        aRun(['user_id' => $other->id]);

        deleteAccount($user, ['confirm_name' => 'Maciek_Turbo'])->assertOk();

        expect(User::query()->pluck('name')->all())->toBe(['Somebody_Else'])
            ->and($other->identities()->count())->toBe(1)
            ->and($other->raceRuns()->count())->toBe(1);
    });

    it('keeps a record that it was done, with the id and nothing else about the person', function () {
        Log::spy();
        $user = accountWithHistory();

        deleteAccount($user, ['confirm_name' => 'Maciek_Turbo'])->assertOk();

        Log::shouldHaveReceived('info')->with('Account erased.', ['user_id' => $user->id])->once();
    });

    it('does not count the copies of the data taken before as tries', function () {
        $user = accountWithHistory();
        $headers = bearerFor($user);

        foreach (range(1, 10) as $ignored) {
            $this->getJson('/api/v1/account/export', $headers)->assertOk();
        }

        $this->deleteJson('/api/v1/account', ['confirm_name' => 'Maciek_Turbo'], $headers)->assertOk();
    });

    it('is limited to five tries an hour for an account', function () {
        $user = accountWithHistory();
        $headers = bearerFor($user);

        $statuses = collect(range(1, 6))->map(fn () => $this->deleteJson('/api/v1/account', ['confirm_name' => 'wrong'], $headers)->getStatusCode());

        expect($statuses->all())->toBe([422, 422, 422, 422, 422, 429]);
    });
});
