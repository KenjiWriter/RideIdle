<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A rule of the game says no: the part is not for sale, the queue of the workshop is full, the car is too low a level.
 * The API answers 422 with `code` (stable, for the client) and the message game.errors.{code} in the language of the driver.
 */
final class RuleRefusedException extends RuntimeException
{
    /**
     * @param  string  $reason  the key under game.errors, and the `code` the API answers with
     * @param  array<string, scalar>  $replace  values for the placeholders of the message
     */
    public function __construct(public readonly string $reason, public readonly array $replace = [])
    {
        parent::__construct("The game refuses: {$reason}.");
    }
}
