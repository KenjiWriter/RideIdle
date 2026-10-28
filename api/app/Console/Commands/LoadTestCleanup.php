<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountErasure;
use Illuminate\Console\Command;

/** Removes the accounts that `loadtest:accounts` made, with everything they did, the way an account is erased. Only names that start with LoadTest are touched. */
final class LoadTestCleanup extends Command
{
    protected $signature = 'loadtest:cleanup
        {--prefix=LoadTest_ : The start of the names of the accounts to remove}
        {--dry-run : Say how many accounts would be removed and remove nothing}';

    protected $description = 'Remove the accounts made for a load test, and everything they did';

    public function handle(AccountErasure $erasure): int
    {
        $prefix = (string) $this->option('prefix');

        if (! str_starts_with($prefix, 'LoadTest') || strlen($prefix) > 24) {
            $this->error('The prefix starts with LoadTest and is at most 24 characters, so that no other account is removed by a slip.');

            return self::FAILURE;
        }

        // The characters that mean something to LIKE are escaped (with !, which no database reads as anything else), so the prefix is taken as it is written.
        $pattern = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%';
        $accounts = User::query()->whereRaw("name like ? escape '!'", [$pattern])->orderBy('id')->get();

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$accounts->count()} accounts would be removed.");

            return self::SUCCESS;
        }

        $removed = 0;

        foreach ($accounts as $account) {
            $erasure->erase($account);
            $removed++;
        }

        $this->info("Removed {$removed} accounts.");

        return self::SUCCESS;
    }
}
