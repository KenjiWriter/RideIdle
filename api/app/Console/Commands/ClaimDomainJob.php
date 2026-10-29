<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DomainJobs;
use Illuminate\Console\Command;

/**
 * What the worker on the server asks first: is there something to do about the domain of a game? It takes the oldest request (no other worker can also get it) and prints it as
 * one line of four fields separated by a tab, which a shell reads with `IFS=$'\t' read -r id action domain email`: the number of the job, what to do (`provision` or `check`),
 * the host and the address for Let's Encrypt (empty for a `check`). Nothing printed means that there is nothing to do.
 */
final class ClaimDomainJob extends Command
{
    protected $signature = 'domains:claim';

    protected $description = 'Take the oldest request about the domain of a game and print it for the worker on the server (id, action, domain, e-mail, separated by tabs)';

    public function handle(DomainJobs $jobs): int
    {
        $job = $jobs->claim();

        if ($job === null) {
            return self::SUCCESS;
        }

        $this->output->writeln(implode("\t", [$job->id, $job->action, $job->domain, (string) $job->email]));

        return self::SUCCESS;
    }
}
