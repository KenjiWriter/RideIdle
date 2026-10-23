<?php

declare(strict_types=1);

use App\Models\User;
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
