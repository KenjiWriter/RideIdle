<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\BotProfileTable;
use Illuminate\Console\Command;
use JsonException;

/** Writes the table of the bot profiles that the bots of the race are made from, so the game does not freeze every car of the pack to find the quickest of a tier. */
final class EconomyBotProfiles extends Command
{
    protected $signature = 'economy:bot-profiles
        {--check : Only check that the committed table is up to date}';

    protected $description = 'Generate content/tables/bot-profiles.json: the dry lap of the quickest stock car of every tier on every track';

    public function handle(BotProfileTable $table): int
    {
        $path = rtrim((string) config('ride.content_path'), '/\\').'/tables/bot-profiles.json';

        if ($this->option('check')) {
            return $this->upToDate($table, $path) ? $this->ok() : $this->stale();
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $table->computedJson());
        $this->info("Wrote {$path}");

        return self::SUCCESS;
    }

    /** The table of the file is the one that is computed now (the numbers are compared, not the bytes, which a checkout may change at the ends of the lines). */
    private function upToDate(BotProfileTable $table, string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        try {
            $committed = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($committed) && $committed == $table->compute();
    }

    private function ok(): int
    {
        $this->info('The table of the bot profiles is up to date.');

        return self::SUCCESS;
    }

    private function stale(): int
    {
        $this->error('The table of the bot profiles is missing or stale. Run php artisan economy:bot-profiles.');

        return self::FAILURE;
    }
}
