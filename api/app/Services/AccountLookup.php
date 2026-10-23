<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds the account a request about personal data is about. The person asks with something they know: the driver name,
 * the e-mail address they write from, or an id the operator already has. An address can lead to more than one account,
 * because the address a provider reports is not unique (two garages may both be signed in with Google accounts that
 * report the same address), so the answer is a list and the operator has to choose.
 */
final class AccountLookup
{
    /** @return Collection<int, User> none, or the one account */
    public function byId(int $id): Collection
    {
        return User::query()->whereKey($id)->get();
    }

    /** @return Collection<int, User> none, or the one account; case and surrounding spaces do not matter */
    public function byName(string $name): Collection
    {
        return User::query()->where('name_key', User::nameKey($name))->get();
    }

    /**
     * Every account the address belongs to: as the e-mail of the account, as the e-mail sign-in, or as the address a
     * provider reported for a sign-in. The address is compared whole and without regard to case.
     *
     * @return Collection<int, User>
     */
    public function byEmail(string $email): Collection
    {
        $address = Accounts::normalizeEmail($email);

        if ($address === '') {
            return new Collection;
        }

        return User::query()
            ->where('email', $address)
            ->orWhereIn('id', AccountIdentity::query()
                ->where('email', $address)
                ->orWhere(fn ($query) => $query->where('provider', AccountIdentity::EMAIL)->where('subject', $address))
                ->select('user_id'))
            ->orderBy('id')
            ->get();
    }
}
