<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Removes guest accounts nobody has returned to. A guest holds no personal data
 * and has no way to be contacted, so keeping it longer has no value.
 */
final class PruneGuestAccounts extends Command
{
    protected $signature = 'accounts:prune-guests
        {--days= : Days without activity before a guest expires (default from config ride.guest_expiry_days)}
        {--dry-run : Only report how many accounts would be removed}';

    protected $description = 'Delete guest accounts that were not used for a long time';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('ride.guest_expiry_days'));
        $threshold = now()->subDays($days);

        // Accounts that never made a request after creation expire by their creation date.
        $expired = User::query()
            ->whereNull('claimed_at')
            ->where(function ($query) use ($threshold): void {
                $query->where('last_seen_at', '<', $threshold)
                    ->orWhere(function ($query) use ($threshold): void {
                        $query->whereNull('last_seen_at')->where('created_at', '<', $threshold);
                    });
            });

        $count = (clone $expired)->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} guest account(s) would be removed (inactive for {$days} days).");

            return self::SUCCESS;
        }

        $expired->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $user->tokens()->delete();
                $user->delete();
            }
        });

        $this->info("Removed {$count} guest account(s) inactive for {$days} days.");

        return self::SUCCESS;
    }
}
