<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\GameUnits;
use Illuminate\Console\Command;

/** Writes the table of what the storage units pay back, which the economy simulator reads, from the real domain of the game. */
final class EconomyUnitReturns extends Command
{
    protected $signature = 'economy:unit-returns
        {--check : Only check that the committed table is up to date}
        {--trials= : How many units of each kind are opened for the table (the number of the committed table when left out)}';

    protected $description = 'Generate content/tables/unit-returns.json: what a storage unit pays back when the find is sold, by kind of unit';

    public function handle(GameUnits $units): int
    {
        $path = $units->path();
        $json = $units->computedJson($this->option('trials') === null ? GameUnits::TRIALS : max(1, (int) $this->option('trials')));

        if ($this->option('check')) {
            if (is_file($path) && file_get_contents($path) === $json) {
                $this->info('The table of the returns of the units is up to date.');

                return self::SUCCESS;
            }

            $this->error('The table of the returns of the units is missing or stale. Run php artisan economy:unit-returns.');

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
