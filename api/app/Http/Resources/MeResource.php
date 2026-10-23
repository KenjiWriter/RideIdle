<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AccountIdentity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class MeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'locale' => $this->locale,
            'is_guest' => $this->isGuest(),
            'has_recovery_code' => $this->hasRecoveryCode(),
            // The ways this account can be signed in to. The address is masked: it is enough to tell which one it is.
            'identities' => $this->identities->sortBy('id')->map(fn (AccountIdentity $identity): array => [
                'provider' => $identity->provider,
                'email' => self::mask($identity->email),
            ])->values()->all(),
        ];
    }

    /** turbo@example.com becomes t***@example.com. */
    private static function mask(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
