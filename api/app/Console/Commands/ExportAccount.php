<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChoosesAnAccount;
use App\Console\Commands\Concerns\RunsInAGame;
use App\Services\AccountExport;
use App\Services\AccountLookup;
use Illuminate\Console\Command;

final class ExportAccount extends Command
{
    use ChoosesAnAccount;
    use RunsInAGame;

    protected $signature = 'accounts:export
        {--id= : The id of the account}
        {--email= : An e-mail address of the account (as its e-mail, or as a sign-in)}
        {--name= : The driver name}
        {--output= : Write to this file (readable by its owner only, and never over a file that is there) instead of the screen}
        {--game= : The slug of the game (the default game when left out)}';

    protected $description = 'Export everything the game holds about an account as JSON, for a person who asked for a copy of their data';

    public function handle(AccountLookup $lookup, AccountExport $export): int
    {
        $user = $this->chosenAccount($lookup);

        if ($user === null) {
            return self::FAILURE;
        }

        $json = json_encode($export->for($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $path = $this->option('output');

        if (! is_string($path) || $path === '') {
            $this->output->write($json);

            return self::SUCCESS;
        }

        if (file_exists($path)) {
            $this->error("{$path} is there already. Nothing was written.");

            return self::FAILURE;
        }

        // The file is made readable by its owner before anything is put in it.
        if (@touch($path) === false || ! @chmod($path, 0600) || file_put_contents($path, $json) === false) {
            $this->error("Could not write {$path}.");

            return self::FAILURE;
        }

        $this->info("Exported account {$user->id} to {$path}.");

        return self::SUCCESS;
    }
}
