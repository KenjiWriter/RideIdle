<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UserMission;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Removes the missions of periods that are long over: the sets of the days after 35 days, those of the weeks after 14 weeks and those of the months after 13
 * months. A mission that was done and was not taken is never removed (it is taken for the driver the next time they look), and neither are the main missions. The
 * rows of the sets themselves are small and stay as the history of what a driver did.
 */
final class MissionsPrune extends Command
{
    /** How long the missions of a kind are kept after their period ended. */
    private const KEEP = ['daily' => '35 days', 'weekly' => '14 weeks', 'monthly' => '13 months'];

    protected $signature = 'missions:prune';

    protected $description = 'Remove the missions of old periods that were closed';

    public function handle(): int
    {
        $removed = 0;

        foreach (self::KEEP as $kind => $keep) {
            $before = Carbon::now()->sub($keep)->getTimestampMs();

            $removed += UserMission::query()->where('kind', $kind)->whereNotNull('closed_at')->where('window_to_ms', '<', $before)
                ->where(fn ($query) => $query->whereNotNull('claimed_at')->orWhereNull('completed_at'))->delete();
        }

        $this->info("Removed {$removed} missions of periods that are over.");

        return self::SUCCESS;
    }
}
