<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** The content that a new game was to start from cannot be had: the panel says it in the words of `admin.games.wizard.warning.<reason>`. */
final class GameStartRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
