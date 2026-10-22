<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A posting would take the balance below zero. The API maps it to the message game.errors.insufficient_funds. */
final class InsufficientFundsException extends RuntimeException
{
    public function __construct(public readonly int $availableCents, public readonly int $requiredCents)
    {
        parent::__construct("Not enough cash: {$availableCents} cents available, {$requiredCents} needed.");
    }
}
