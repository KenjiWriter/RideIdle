<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Run\Rarity;
use App\Models\Part;
use App\Services\PartAffixes;
use Illuminate\Console\Command;

/**
 * Gives the parts that were made before the affixes their affixes, once: the uncommon parts and above that have none. The draw is the one of the crates and of
 * the merges, from the same seed (the driver, the part and the rarity), so a part gets the affixes it would have had; a part that has some is left as it is, and
 * the command can be run again. The runs that were frozen before keep their old numbers until they are frozen again (a change of the parts, of the track, a refreeze).
 */
final class RollPartAffixes extends Command
{
    protected $signature = 'parts:roll-affixes
        {--dry-run : Only report how many parts would get affixes}';

    protected $description = 'Give the parts of a rarity above common that have no affixes their affixes (once)';

    public function handle(PartAffixes $affixes): int
    {
        $given = 0;

        Part::query()->where('rarity', '>', Rarity::Common->value)->whereNull('affixes')->chunkById(200, function ($parts) use ($affixes, &$given): void {
            foreach ($parts as $part) {
                if ($this->option('dry-run')) {
                    $given++;

                    continue;
                }

                $given += $affixes->give($part, Rarity::from((int) $part->rarity))->wasChanged('affixes') ? 1 : 0;
            }
        });

        $this->info($this->option('dry-run') ? "{$given} part(s) would get affixes." : "{$given} part(s) got affixes.");

        return self::SUCCESS;
    }
}
