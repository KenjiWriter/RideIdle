<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** The account already has an identity of this provider, and an account has at most one of each. */
final class ProviderAlreadyLinkedException extends RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct("The account already has a {$provider} identity.");
    }
}
