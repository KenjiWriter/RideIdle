<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Makes guest accounts for a load test, with a token each, written to a file (never to the screen). The sign-up of a guest is limited to ten an hour for an address,
 * so a test that needs hundreds of drivers cannot make them through the API. `loadtest:cleanup` removes them again.
 */
final class LoadTestAccounts extends Command
{
    public const PREFIX = 'LoadTest_';

    protected $signature = 'loadtest:accounts
        {count : How many accounts to make (1 to 2000)}
        {--output= : The file the names and tokens are written to (readable by its owner only, and never over a file that is there)}
        {--prefix=LoadTest_ : The start of their names, which loadtest:cleanup finds them by}
        {--force : Make them also where APP_ENV is production}';

    protected $description = 'Make guest accounts with tokens for a load test (loadtest:cleanup removes them)';

    public function handle(): int
    {
        $count = (string) $this->argument('count');
        $prefix = (string) $this->option('prefix');
        $path = (string) $this->option('output');

        if (! ctype_digit($count) || (int) $count < 1 || (int) $count > 2000) {
            $this->error('The count is a whole number from 1 to 2000.');

            return self::FAILURE;
        }

        if (! str_starts_with($prefix, 'LoadTest') || strlen($prefix) > 24) {
            $this->error('The prefix starts with LoadTest and is at most 24 characters, so that nothing else is taken for a test account.');

            return self::FAILURE;
        }

        if ($path === '') {
            $this->error('Say where the tokens go with --output=FILE.');

            return self::FAILURE;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('This is a production environment. Test accounts there are real rows in the real database; run it with --force if that is what you want, and loadtest:cleanup afterwards.');

            return self::FAILURE;
        }

        if (file_exists($path)) {
            $this->error("{$path} is there already. Nothing was made.");

            return self::FAILURE;
        }

        // The file is made readable by its owner before anything is put in it.
        if (@touch($path) === false || ! @chmod($path, 0600)) {
            $this->error("Could not write {$path}.");

            return self::FAILURE;
        }

        $made = [];
        $number = 0;

        while (count($made) < (int) $count) {
            $number++;
            $name = $prefix.str_pad((string) $number, 4, '0', STR_PAD_LEFT);

            if (User::query()->where('name_key', User::nameKey($name))->exists()) {
                continue;
            }

            $user = new User(['name' => $name, 'locale' => 'en']);
            $user->forceFill(['name_key' => User::nameKey($name), 'last_seen_at' => now()]);
            $user->save();

            $made[] = ['name' => $name, 'token' => $user->createToken('loadtest')->plainTextToken];
        }

        file_put_contents($path, json_encode($made, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->info("Made {$count} accounts ({$made[0]['name']} to {$made[count($made) - 1]['name']}); their tokens are in {$path}. Remove them with: php artisan loadtest:cleanup");

        return self::SUCCESS;
    }
}
