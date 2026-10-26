<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\RarityPace;
use Illuminate\Console\Command;
use JsonException;

/** Writes the table of the pace of the rarities that the crates of the economy simulator read, so the simulator does not draw a thousand players on every run. */
final class EconomyRarityPace extends Command
{
    protected $signature = 'economy:rarity-pace
        {--check : Only check that the committed table is up to date}';

    protected $description = 'Generate content/tables/rarity-pace.json: what the rarity of the parts is worth in laps an hour, after n crates';

    public function handle(RarityPace $pace): int
    {
        $path = rtrim((string) config('ride.content_path'), '/\\').'/tables/rarity-pace.json';

        if ($this->option('check')) {
            return $this->upToDate($pace, $path) ? $this->ok() : $this->stale();
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $pace->computedJson());
        $this->info("Wrote {$path}");

        return self::SUCCESS;
    }

    /** The table of the file is the one that is computed now (the numbers are compared, not the bytes, which a checkout may change at the ends of the lines). */
    private function upToDate(RarityPace $pace, string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        try {
            $committed = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($committed) && isset($committed['trials']) && $committed == $pace->compute((int) $committed['trials']);
    }

    private function ok(): int
    {
        $this->info('The table of the pace of the rarities is up to date.');

        return self::SUCCESS;
    }

    private function stale(): int
    {
        $this->error('The table of the pace of the rarities is missing or stale. Run php artisan economy:rarity-pace.');

        return self::FAILURE;
    }
}
