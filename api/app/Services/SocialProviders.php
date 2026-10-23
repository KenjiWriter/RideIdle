<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountIdentity;

/**
 * Which of the sign-ins with another service are on. A provider is on only when it has all its keys, so a game
 * that was set up with Google alone offers Google alone, and a half filled configuration offers nothing that would
 * fail when pressed.
 */
final class SocialProviders
{
    /** @return list<string> the providers that are on, in the order they are offered */
    public function enabled(): array
    {
        return array_values(array_filter(AccountIdentity::SOCIAL, fn (string $provider): bool => $this->isEnabled($provider)));
    }

    public function isEnabled(string $provider): bool
    {
        if (! in_array($provider, AccountIdentity::SOCIAL, true)) {
            return false;
        }

        $keys = (array) config("services.{$provider}");

        if (blank($keys['client_id'] ?? null)) {
            return false;
        }

        // Apple's client secret is a signed token: given ready, or made from the team, the key and the private key.
        if ($provider === AccountIdentity::APPLE) {
            return filled($keys['client_secret'] ?? null)
                || (filled($keys['team_id'] ?? null) && filled($keys['key_id'] ?? null) && filled($keys['private_key'] ?? null));
        }

        return filled($keys['client_secret'] ?? null);
    }
}
