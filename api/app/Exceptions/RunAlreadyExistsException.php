<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A driver asked for a run while already having one. The API maps it to the message game.errors.run_exists. */
final class RunAlreadyExistsException extends RuntimeException
{
    public function __construct(public readonly int $runId)
    {
        parent::__construct("The driver already has the run {$runId}.");
    }
}
