<?php

declare(strict_types=1);

use App\Models\AccountToken;
use App\Models\User;
use App\Services\AccountTokens;
use Illuminate\Support\Carbon;

function tokens(): AccountTokens
{
    return new AccountTokens;
}

afterEach(fn () => Carbon::setTestNow());

it('gives a secret that is not stored, only its hash', function () {
    $secret = tokens()->issue(AccountToken::CLAIM, 1_800);

    $row = AccountToken::query()->firstOrFail();

    expect($secret)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($row->token_hash)->toBe(AccountTokens::hash($secret))
        ->and($row->token_hash)->not->toContain($secret)
        ->and($row->purpose)->toBe('claim')
        ->and($row->used_at)->toBeNull();
});

it('gives a different secret every time', function () {
    $secrets = collect(range(1, 20))->map(fn () => tokens()->issue(AccountToken::CLAIM, 60))->unique();

    expect($secrets)->toHaveCount(20);
});

it('keeps what the token is for', function () {
    $user = User::factory()->guest()->create();
    $consent = now()->startOfSecond();

    $secret = tokens()->issue(AccountToken::CLAIM, 1_800, ['user_id' => $user->id, 'email' => 'turbo@example.com', 'consent_at' => $consent]);

    $token = tokens()->consume(AccountToken::CLAIM, $secret);

    expect($token->user_id)->toBe($user->id)
        ->and($token->email)->toBe('turbo@example.com')
        ->and($token->consent_at->equalTo($consent))->toBeTrue();
});

it('can be used once', function () {
    $secret = tokens()->issue(AccountToken::LOGIN, 1_800);

    expect(tokens()->consume(AccountToken::LOGIN, $secret))->not->toBeNull()
        ->and(tokens()->consume(AccountToken::LOGIN, $secret))->toBeNull();
});

it('works for its own purpose only', function () {
    $secret = tokens()->issue(AccountToken::LOGIN, 1_800);

    expect(tokens()->consume(AccountToken::CLAIM, $secret))->toBeNull()
        ->and(tokens()->consume(AccountToken::RESULT, $secret))->toBeNull()
        // The wrong purpose did not spend it.
        ->and(tokens()->consume(AccountToken::LOGIN, $secret))->not->toBeNull();
});

it('stops working when it expires', function () {
    Carbon::setTestNow('2026-10-23 12:00:00');
    $secret = tokens()->issue(AccountToken::CLAIM, 600);

    Carbon::setTestNow('2026-10-23 12:09:59');
    expect(tokens()->consume(AccountToken::CLAIM, $secret))->not->toBeNull();

    Carbon::setTestNow('2026-10-23 12:00:00');
    $other = tokens()->issue(AccountToken::CLAIM, 600);

    Carbon::setTestNow('2026-10-23 12:10:00');
    expect(tokens()->consume(AccountToken::CLAIM, $other))->toBeNull();
});

it('does not know a secret it never gave', function (string $secret) {
    tokens()->issue(AccountToken::CLAIM, 600);

    expect(tokens()->consume(AccountToken::CLAIM, $secret))->toBeNull();
})->with([
    'a guess' => ['not-a-real-secret'],
    'empty' => [''],
    'too long' => [str_repeat('a', 129)],
]);

it('uses a token once even when it is asked for at the same moment from two places', function () {
    $secret = tokens()->issue(AccountToken::CLAIM, 600);

    // Both callers read the row as unused; only the one whose UPDATE finds it still unused succeeds.
    $first = tokens()->consume(AccountToken::CLAIM, $secret);
    $second = tokens()->consume(AccountToken::CLAIM, $secret);

    expect([$first !== null, $second !== null])->toBe([true, false])
        ->and(AccountToken::query()->whereNotNull('used_at')->count())->toBe(1);
});

it('removes the tokens that expired or were used more than a day ago, and keeps the rest', function () {
    Carbon::setTestNow('2026-10-23 12:00:00');
    $old = tokens()->issue(AccountToken::CLAIM, 60);
    $spent = tokens()->issue(AccountToken::LOGIN, 600);
    tokens()->consume(AccountToken::LOGIN, $spent);

    Carbon::setTestNow('2026-10-25 12:00:00');
    $fresh = tokens()->issue(AccountToken::CLAIM, 600);

    expect(tokens()->pruneExpired())->toBe(2)
        ->and(AccountToken::query()->count())->toBe(1)
        ->and(tokens()->consume(AccountToken::CLAIM, $fresh))->not->toBeNull()
        ->and($old)->not->toBe($spent);
});

it('prunes with a command', function () {
    Carbon::setTestNow('2026-10-23 12:00:00');
    tokens()->issue(AccountToken::CLAIM, 60);
    Carbon::setTestNow('2026-10-25 12:00:00');

    $this->artisan('accounts:prune-tokens')->expectsOutput('Removed 1 account token(s).')->assertSuccessful();

    expect(AccountToken::query()->count())->toBe(0);
});

it('can be bound to a verifier, and a wrong verifier does not spend it', function () {
    $verifier = 'verifier-kept-by-the-browser';
    $secret = tokens()->issue(AccountToken::RESULT, 600, ['challenge' => AccountTokens::hash($verifier)]);

    expect(tokens()->consume(AccountToken::RESULT, $secret, 'a-verifier-of-somebody-else'))->toBeNull()
        ->and(tokens()->consume(AccountToken::RESULT, $secret))->not->toBeNull(); // not burnt by the wrong one

    $other = tokens()->issue(AccountToken::RESULT, 600, ['challenge' => AccountTokens::hash($verifier)]);

    expect(tokens()->consume(AccountToken::RESULT, $other, $verifier))->not->toBeNull()
        ->and(tokens()->consume(AccountToken::RESULT, $other, $verifier))->toBeNull();
});

it('does not take a token that was not bound for one that asks for a verifier', function () {
    $secret = tokens()->issue(AccountToken::RESULT, 600);

    expect(tokens()->consume(AccountToken::RESULT, $secret, 'any-verifier'))->toBeNull();
});

it('keeps what the provider said and what the browser must prove', function () {
    $secret = tokens()->issue(AccountToken::RESULT, 600, ['provider' => 'google', 'intent' => 'login', 'subject' => '1098765', 'email' => 'turbo@example.com', 'challenge' => AccountTokens::hash('v')]);

    $token = tokens()->consume(AccountToken::RESULT, $secret, 'v');

    expect($token->provider)->toBe('google')
        ->and($token->subject)->toBe('1098765')
        ->and($token->email)->toBe('turbo@example.com');
});
