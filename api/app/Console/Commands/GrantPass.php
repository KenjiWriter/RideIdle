<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChoosesAnAccount;
use App\Console\Commands\Concerns\RunsInAGame;
use App\Exceptions\RuleRefusedException;
use App\Services\AccountLookup;
use App\Services\Purchases;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Gives an account the pass of the endless refill without a payment (support, a promotion, and until a payment provider is connected the only way to have it).
 * It is recorded in the book of the purchases as a purchase of nothing (amount 0, provider `operator`), and the days are added to the end of the pass the account has.
 */
final class GrantPass extends Command
{
    use ChoosesAnAccount;
    use RunsInAGame;

    protected $signature = 'premium:pass
        {days? : How many days to give (the days of one purchase in the rules when left out)}
        {--id= : The id of the account}
        {--email= : An e-mail address of the account (as its e-mail, or as a sign-in)}
        {--name= : The driver name}
        {--key= : Makes the gift happen once: run it again with the same key and nothing more is given (default: a new one every time)}
        {--game= : The slug of the game (the default game when left out)}';

    protected $description = 'Give an account the pass of the endless refill, without a payment';

    public function handle(AccountLookup $lookup, Purchases $purchases): int
    {
        $user = $this->chosenAccount($lookup);

        if ($user === null) {
            return self::FAILURE;
        }

        $days = $this->argument('days');

        if ($days !== null && (! ctype_digit((string) $days) || (int) $days < 1 || (int) $days > 3650)) {
            $this->error('The days are a whole number from 1 to 3650.');

            return self::FAILURE;
        }

        $key = trim((string) $this->option('key'));
        $reference = $key === '' ? (string) Str::uuid() : $key;

        if (strlen($reference) > 128) {
            $this->error('The key is too long.');

            return self::FAILURE;
        }

        try {
            $purchase = $purchases->fulfilPass($user->id, 'operator', $reference, $days === null ? null : (int) $days, 0);
        } catch (RuleRefusedException) {
            $this->error('The game does not sell the pass (it is not in the rules, or it is switched off).');

            return self::FAILURE;
        }

        $until = $user->wallet()->value('infinity_until');

        $this->info("Account {$user->id} has the pass of the endless refill until {$until} (purchase {$purchase->id}).");

        return self::SUCCESS;
    }
}
