<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChoosesAnAccount;
use App\Console\Commands\Concerns\RunsInAGame;
use App\Services\AccountLookup;
use App\Services\Premium;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Gives premium coins to an account that did not pay for them (support making up for a fault, a promotion). The ledger keeps the reason. */
final class GrantPremium extends Command
{
    use ChoosesAnAccount;
    use RunsInAGame;

    protected $signature = 'premium:grant
        {coins : How many coins to give}
        {--id= : The id of the account}
        {--email= : An e-mail address of the account (as its e-mail, or as a sign-in)}
        {--name= : The driver name}
        {--reason=gift : What it is for, one word for the ledger (gift, support, promotion)}
        {--key= : Makes the gift happen once: run it again with the same key and nothing more is given (default: a new one every time)}
        {--game= : The slug of the game (the default game when left out)}';

    protected $description = 'Give premium coins to an account, without a payment';

    public function handle(AccountLookup $lookup, Premium $premium): int
    {
        $user = $this->chosenAccount($lookup);

        if ($user === null) {
            return self::FAILURE;
        }

        $coins = (string) $this->argument('coins');
        $reason = trim((string) $this->option('reason'));

        if (! ctype_digit($coins) || (int) $coins < 1 || (int) $coins > 1_000_000) {
            $this->error('The coins are a whole number from 1 to 1000000.');

            return self::FAILURE;
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $reason) !== 1) {
            $this->error('The reason is one word of lower case letters, digits and underscores.');

            return self::FAILURE;
        }

        $key = trim((string) $this->option('key'));
        $idemKey = 'grant:'.($key === '' ? (string) Str::uuid() : $key);

        if (strlen($idemKey) > 128) {
            $this->error('The key is too long.');

            return self::FAILURE;
        }

        $premium->grant($user->id, (int) $coins, $reason, $idemKey);

        $this->info("Account {$user->id} has {$premium->balance($user->id)} premium coins now ({$reason}: {$coins}).");

        return self::SUCCESS;
    }
}
