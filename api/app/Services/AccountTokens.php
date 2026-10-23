<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountToken;
use Carbon\CarbonInterface;

/**
 * Issues and uses the one-time secrets of the account flows: the link of an e-mail, the state of a sign-in with a
 * provider, the code that trades a finished sign-in for a device token.
 *
 * A secret is 32 random bytes and is returned once, in the link or the redirect that carries it. Only a keyed hash is
 * kept, so a leaked database does not reveal a link that still works. A secret is short-lived and works once: using
 * it is a single UPDATE of the row that is still unused and unexpired, so two requests with the same secret cannot
 * both succeed, whatever their timing.
 */
final class AccountTokens
{
    /**
     * @param  array{user_id?: int|null, provider?: string|null, intent?: string|null, email?: string|null, consent_at?: CarbonInterface|null, challenge?: string|null, subject?: string|null}  $attributes
     * @return string the secret, to be given to the person and never stored
     */
    public function issue(string $purpose, int $ttlSeconds, array $attributes = []): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        AccountToken::query()->create([
            ...$attributes,
            'purpose' => $purpose,
            'token_hash' => self::hash($secret),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return $secret;
    }

    /**
     * Uses a secret of the given purpose. Returns its row, or null when the secret is unknown, of another purpose,
     * expired or already used. The caller cannot tell these apart, and neither can anyone who guesses.
     *
     * With a `$verifier` the secret is used only if the token was bound to it (its `challenge` is the hash of the
     * verifier). A wrong verifier does not spend the secret, so nobody can burn it without knowing the verifier.
     */
    public function consume(string $purpose, string $secret, ?string $verifier = null): ?AccountToken
    {
        if ($secret === '' || strlen($secret) > 128) {
            return null;
        }

        $hash = self::hash($secret);

        $used = AccountToken::query()
            ->where('token_hash', $hash)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->when($verifier !== null, fn ($query) => $query->where('challenge', self::hash($verifier)))
            ->update(['used_at' => now()]);

        return $used === 1 ? AccountToken::query()->where('token_hash', $hash)->first() : null;
    }

    /** Removes the tokens that expired or were used more than a day ago. */
    public function pruneExpired(): int
    {
        $threshold = now()->subDay();

        return AccountToken::query()
            ->where('expires_at', '<', $threshold)
            ->orWhere('used_at', '<', $threshold)
            ->delete();
    }

    /** Keyed hash of a secret, safe to index and compare. */
    public static function hash(string $secret): string
    {
        return hash_hmac('sha256', $secret, 'account-token:'.config('app.key'));
    }
}
