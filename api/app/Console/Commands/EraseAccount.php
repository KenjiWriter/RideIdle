<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChoosesAnAccount;
use App\Services\AccountErasure;
use App\Services\AccountLookup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class EraseAccount extends Command
{
    use ChoosesAnAccount;

    protected $signature = 'accounts:delete
        {--id= : The id of the account}
        {--email= : An e-mail address of the account (as its e-mail, or as a sign-in)}
        {--name= : The driver name}
        {--dry-run : Say what would be removed and remove nothing}
        {--force : Do not ask for confirmation}';

    protected $description = 'Delete an account and everything about it, for a person who asked to be forgotten (this cannot be undone)';

    public function handle(AccountLookup $lookup, AccountErasure $erasure): int
    {
        $user = $this->chosenAccount($lookup);

        if ($user === null) {
            return self::FAILURE;
        }

        $this->line("Account {$user->id} ({$user->name}) holds:");
        $this->table(['kind', 'count'], $this->rows($erasure->inventory($user)));

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was removed.');

            return self::SUCCESS;
        }

        // Without --force the operator is asked, and a run with no one to ask (no input) removes nothing.
        if (! $this->option('force') && ! $this->confirm("Delete account {$user->id} ({$user->name}) and everything about it? This cannot be undone.")) {
            $this->warn('Nothing was removed.');

            return self::FAILURE;
        }

        $removed = $erasure->erase($user);

        // The record that the request was carried out holds the id of the account and nothing else about the person.
        Log::info('Account erased.', ['user_id' => $user->id]);

        $this->info("Account {$user->id} was deleted. Removed:");
        $this->table(['kind', 'count'], $this->rows($removed));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{string, int}>
     */
    private function rows(array $counts): array
    {
        return array_map(fn (string $kind, int $count): array => [$kind, $count], array_keys($counts), $counts);
    }
}
