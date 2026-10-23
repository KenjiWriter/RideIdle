<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\SettlementEvent;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Removes an account and every trace of it that the game can reach, for a person who asked to be forgotten: the account
 * itself, how it signs in, the devices that are signed in, the links that wait, everything it earned and did, and the
 * e-mails to its addresses that are still in the queue or failed to be sent.
 *
 * Everything goes in one transaction, so a failure leaves the account as it was and not half removed. The order is the
 * order of the foreign keys, from what hangs on a run to the account, so it does not depend on the database cascading.
 *
 * What it cannot reach: the counters that limit abuse (they live up to an hour and then go), and logs and backups
 * outside the application.
 */
final class AccountErasure
{
    /**
     * What the account holds, by kind, which is what erasing it removes.
     *
     * @return array<string, int>
     */
    public function inventory(User $user): array
    {
        $runs = RaceRun::query()->where('user_id', $user->id)->select('id');
        $settlements = Settlement::query()->whereIn('race_run_id', $runs)->select('id');

        return [
            'accounts' => 1,
            'sign-ins' => AccountIdentity::query()->where('user_id', $user->id)->count(),
            'devices' => PersonalAccessToken::query()->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->count(),
            'waiting links' => AccountToken::query()->where('user_id', $user->id)->count(),
            'wallets' => Wallet::query()->where('user_id', $user->id)->count(),
            'runs' => $runs->count(),
            'settlements' => $settlements->count(),
            'settlement events' => SettlementEvent::query()->whereIn('settlement_id', $settlements)->count(),
            'ledger entries' => LedgerEntry::query()->where('user_id', $user->id)->count(),
            'queued e-mails' => count($this->mailsTo($user, 'jobs')),
            'failed e-mails' => count($this->mailsTo($user, 'failed_jobs')),
        ];
    }

    /**
     * Removes the account. Returns what was removed, by kind, as the inventory said it before.
     *
     * @return array<string, int>
     */
    public function erase(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $removed = $this->inventory($user);
            $queued = $this->mailsTo($user, 'jobs');
            $failed = $this->mailsTo($user, 'failed_jobs');
            $addresses = $this->addressesOf($user);

            $runs = RaceRun::query()->where('user_id', $user->id)->select('id');
            $settlements = Settlement::query()->whereIn('race_run_id', $runs)->select('id');

            SettlementEvent::query()->whereIn('settlement_id', $settlements)->delete();
            LedgerEntry::query()->where('user_id', $user->id)->delete();
            Settlement::query()->whereIn('race_run_id', $runs)->delete();
            RaceRun::query()->where('user_id', $user->id)->delete();
            Wallet::query()->where('user_id', $user->id)->delete();
            AccountToken::query()->where('user_id', $user->id)->delete();
            AccountIdentity::query()->where('user_id', $user->id)->delete();
            $user->tokens()->delete();

            // Tables of the framework that may hold the account: the sessions of a browser, the requests for a new password.
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            if ($addresses !== [] && Schema::hasTable('password_reset_tokens')) {
                DB::table('password_reset_tokens')->whereIn('email', $addresses)->delete();
            }

            if ($queued !== []) {
                DB::table('jobs')->whereIn('id', $queued)->delete();
            }

            if ($failed !== []) {
                DB::table('failed_jobs')->whereIn('id', $failed)->delete();
            }

            $user->delete();

            return $removed;
        });
    }

    /**
     * The addresses the account is known by, as they were written for sending: the e-mail of the account, the e-mail
     * sign-in, what the providers reported, and the addresses of links that wait.
     *
     * @return list<string>
     */
    private function addressesOf(User $user): array
    {
        $identities = AccountIdentity::query()->where('user_id', $user->id)->get();

        return collect([$user->email])
            ->merge($identities->pluck('email'))
            ->merge($identities->where('provider', AccountIdentity::EMAIL)->pluck('subject'))
            ->merge(AccountToken::query()->where('user_id', $user->id)->pluck('email'))
            ->filter(fn (mixed $address): bool => is_string($address) && $address !== '')
            ->map(fn (string $address): string => Accounts::normalizeEmail($address))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The ids of the rows of a queue table that are about an address of the account. The payload is searched for the
     * address as it is, and as JSON writes it, in PHP: a pattern in SQL would take _ and % of an address for wildcards,
     * and it would not be the same on every database.
     *
     * @return list<int|string>
     */
    private function mailsTo(User $user, string $table): array
    {
        $addresses = $this->addressesOf($user);

        if ($addresses === [] || ! Schema::hasTable($table)) {
            return [];
        }

        $needles = collect($addresses)
            ->flatMap(fn (string $address): array => [$address, substr((string) json_encode($address, JSON_UNESCAPED_UNICODE), 1, -1)])
            ->unique()
            ->all();

        $found = [];

        DB::table($table)->select(['id', 'payload'])->orderBy('id')->chunkById(200, function ($rows) use ($needles, &$found): void {
            foreach ($rows as $row) {
                foreach ($needles as $needle) {
                    if (str_contains(strtolower((string) $row->payload), $needle)) {
                        $found[] = $row->id;

                        break;
                    }
                }
            }
        });

        return $found;
    }
}
