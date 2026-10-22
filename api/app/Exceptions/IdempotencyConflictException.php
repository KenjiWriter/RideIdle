<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

/**
 * An idempotency key was reused for a different posting. That is a bug in the caller, never a retry:
 * a retry repeats the same posting.
 */
final class IdempotencyConflictException extends LogicException
{
    public function __construct(string $key)
    {
        parent::__construct("The idempotency key {$key} was already used for a different posting.");
    }
}
