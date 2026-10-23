<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountTokens;
use Illuminate\Console\Command;

/** Removes the one-time secrets of the account flows that expired or were used more than a day ago. */
final class PruneAccountTokens extends Command
{
    protected $signature = 'accounts:prune-tokens';

    protected $description = 'Delete expired and used account tokens';

    public function handle(AccountTokens $tokens): int
    {
        $this->info("Removed {$tokens->pruneExpired()} account token(s).");

        return self::SUCCESS;
    }
}
