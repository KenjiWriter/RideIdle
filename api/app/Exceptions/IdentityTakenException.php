<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The identity (an e-mail, a Google account...) already belongs to another account. Two accounts are never merged,
 * because that would open the way to abuse, so the driver chooses which one to keep.
 */
final class IdentityTakenException extends RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct("This {$provider} identity already belongs to another account.");
    }
}
