<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Sanctum\PersonalAccessToken;

function guestSeen(string $daysAgo, array $attributes = []): User
{
    return User::factory()->guest()->create(array_merge([
        'last_seen_at' => now()->subDays((int) $daysAgo),
    ], $attributes));
}

it('removes guests inactive for longer than the configured time', function () {
    $expired = guestSeen('100');
    $recent = guestSeen('10');
    $claimedOld = User::factory()->create(['last_seen_at' => now()->subDays(400)]);

    $this->artisan('accounts:prune-guests')->assertSuccessful();

    expect(User::query()->find($expired->id))->toBeNull()
        ->and(User::query()->find($recent->id))->not->toBeNull()
        ->and(User::query()->find($claimedOld->id))->not->toBeNull();
});

it('expires guests that never made a request by their creation date', function () {
    $neverSeen = User::factory()->guest()->create(['last_seen_at' => null, 'created_at' => now()->subDays(120)]);
    $newNeverSeen = User::factory()->guest()->create(['last_seen_at' => null, 'created_at' => now()->subDays(5)]);

    $this->artisan('accounts:prune-guests')->assertSuccessful();

    expect(User::query()->find($neverSeen->id))->toBeNull()
        ->and(User::query()->find($newNeverSeen->id))->not->toBeNull();
});

it('deletes the tokens of removed guests', function () {
    $expired = guestSeen('100');
    $expired->createToken('guest-device');

    expect(PersonalAccessToken::query()->count())->toBe(1);

    $this->artisan('accounts:prune-guests')->assertSuccessful();

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('honours the days option', function () {
    $guest = guestSeen('40');

    $this->artisan('accounts:prune-guests --days=30')->assertSuccessful();

    expect(User::query()->find($guest->id))->toBeNull();
});

it('reports without deleting in dry-run mode', function () {
    $guest = guestSeen('100');

    $this->artisan('accounts:prune-guests --dry-run')
        ->expectsOutputToContain('1 guest account(s) would be removed')
        ->assertSuccessful();

    expect(User::query()->find($guest->id))->not->toBeNull();
});

it('is scheduled every day', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command);

    expect($commands->contains(fn (string $command) => str_contains($command, 'accounts:prune-guests')))->toBeTrue();
});

it('records activity at most once every ten minutes', function () {
    $token = $this->postJson('/api/v1/players', ['name' => 'Kierowca'])->json('token');
    $headers = ['Authorization' => "Bearer {$token}"];

    $created = User::query()->firstOrFail()->last_seen_at;

    $this->travel(5)->minutes();
    $this->getJson('/api/v1/me', $headers)->assertOk();
    expect(User::query()->firstOrFail()->last_seen_at->equalTo($created))->toBeTrue();

    $this->travel(6)->minutes();
    $this->getJson('/api/v1/me', $headers)->assertOk();
    expect(User::query()->firstOrFail()->last_seen_at->greaterThan($created))->toBeTrue();
});
