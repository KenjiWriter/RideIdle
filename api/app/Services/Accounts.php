<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\IdentityTakenException;
use App\Exceptions\ProviderAlreadyLinkedException;
use App\Models\AccountIdentity;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Links the ways of signing in to an account. Linking the first one is what secures a guest account: from then on
 * the account does not expire, and it can be reached on another device without a recovery code.
 */
final class Accounts
{
    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * What linking a sign-in asks of the request: the terms accepted and the age confirmed, unless the account has
     * done that already. Once is enough; a driver who adds a second sign-in is not asked the same again.
     *
     * @return array<string, list<string>>
     */
    public static function consentRules(User $user): array
    {
        return $user->hasConsented() ? [] : ['accept_terms' => ['accepted'], 'confirm_age' => ['accepted']];
    }

    /** The moment to record as the consent of a link that is being asked for: none when it is on record already. */
    public static function consentAt(User $user): ?CarbonInterface
    {
        return $user->hasConsented() ? null : now();
    }

    /** The account an identity belongs to, if any. */
    public function userFor(string $provider, string $subject): ?User
    {
        return User::query()
            ->whereIn('id', AccountIdentity::query()->where('provider', $provider)->where('subject', $subject)->select('user_id'))
            ->first();
    }

    /**
     * Links an identity to an account, which secures a guest account, and saves when the driver accepted the terms
     * and confirmed their age if that is given. Linking the identity the account already has changes nothing.
     * Only an e-mail identity is also the e-mail of the account, because only an e-mail was proven to be theirs by
     * a link; what a provider says about an e-mail is kept for display and never used to find an account.
     *
     * @throws IdentityTakenException when the identity belongs to another account
     * @throws ProviderAlreadyLinkedException when the account already has another identity of this provider
     */
    public function link(User $user, string $provider, string $subject, ?string $email = null, ?CarbonInterface $consentAt = null): AccountIdentity
    {
        try {
            return DB::transaction(function () use ($user, $provider, $subject, $email, $consentAt): AccountIdentity {
                // Linking two identities for one account at once must not race past the checks below.
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                $existing = AccountIdentity::query()->where('provider', $provider)->where('subject', $subject)->first();

                if ($existing !== null) {
                    return (int) $existing->user_id === (int) $locked->id ? $existing : throw new IdentityTakenException($provider);
                }

                if ($locked->identities()->where('provider', $provider)->exists()) {
                    throw new ProviderAlreadyLinkedException($provider);
                }

                $identity = $locked->identities()->create(['provider' => $provider, 'subject' => $subject, 'email' => $email]);

                $locked->forceFill(array_filter([
                    'claimed_at' => $locked->claimed_at === null ? now() : null,
                    'terms_accepted_at' => $consentAt !== null && $locked->terms_accepted_at === null ? $consentAt : null,
                    'age_confirmed_at' => $consentAt !== null && $locked->age_confirmed_at === null ? $consentAt : null,
                    'email' => $provider === AccountIdentity::EMAIL ? $subject : null,
                    'email_verified_at' => $provider === AccountIdentity::EMAIL ? now() : null,
                ]))->save();

                $user->setRawAttributes($locked->getAttributes(), true);

                return $identity;
            });
        } catch (UniqueConstraintViolationException) {
            // Another request linked the same identity, or took the e-mail, between the check and the insert.
            throw new IdentityTakenException($provider);
        }
    }
}
