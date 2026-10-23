<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\User;
use App\Services\AccountLookup;

/**
 * The part of a command about personal data that says which account it is about: exactly one of --id, --email and
 * --name. A command that deletes or hands out data must not guess, so when nothing or more than one account matches
 * it says so and chooses none.
 */
trait ChoosesAnAccount
{
    protected function chosenAccount(AccountLookup $lookup): ?User
    {
        // From a shell every option is a string; a caller in code may pass the id as a number.
        $given = array_map(
            static fn (mixed $value): string => trim((string) $value),
            array_filter(
                ['id' => $this->option('id'), 'email' => $this->option('email'), 'name' => $this->option('name')],
                static fn (mixed $value): bool => (is_string($value) || is_int($value)) && trim((string) $value) !== '',
            ),
        );

        if (count($given) !== 1) {
            $this->error('Give exactly one of --id, --email or --name.');

            return null;
        }

        $found = match (array_key_first($given)) {
            'id' => ctype_digit($given['id']) ? $lookup->byId((int) $given['id']) : null,
            'email' => $lookup->byEmail($given['email']),
            default => $lookup->byName($given['name']),
        };

        if ($found === null) {
            $this->error('The id must be a number.');

            return null;
        }

        if ($found->isEmpty()) {
            $this->error('No account matches.');

            return null;
        }

        if ($found->count() > 1) {
            $this->error('More than one account matches. Choose one by its id (--id):');
            $this->table(
                ['id', 'name', 'secured', 'last seen', 'signs in with'],
                $found->map(fn (User $user): array => [
                    $user->id,
                    $user->name,
                    $user->claimed_at?->toDateString() ?? 'guest',
                    $user->last_seen_at?->toDateString() ?? '-',
                    $user->identities()->pluck('provider')->implode(', '),
                ])->all(),
            );

            return null;
        }

        return $found->first();
    }
}
