<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Settlement\EloTable;
use Illuminate\Console\Command;

/** Writes the expected score table that ratings read, so no power is computed while the game runs. */
final class GenerateEloTable extends Command
{
    protected $signature = 'content:elo-table
        {--check : Only check that the committed table is up to date}';

    protected $description = 'Generate content/tables/elo-expected-ppm.json';

    public function handle(): int
    {
        $path = config('ride.content_path').'/tables/elo-expected-ppm.json';
        $json = EloTable::computedJson();

        if ($this->option('check')) {
            if (is_file($path) && file_get_contents($path) === $json) {
                $this->info('The Elo table is up to date.');

                return self::SUCCESS;
            }

            $this->error('The Elo table is missing or stale. Run php artisan content:elo-table.');

            return self::FAILURE;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $json);
        $this->info("Wrote {$path}");

        return self::SUCCESS;
    }
}
