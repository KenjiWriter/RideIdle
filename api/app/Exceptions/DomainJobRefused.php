<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A request about the domain of a game that there is no point in making: the panel says why in the words of `admin.domain.refused.<reason>`. */
final class DomainJobRefused extends RuntimeException
{
    /** The thing asked for is none of the things that the worker does. */
    public const ACTION = 'action';

    /** The game has no domain, or one that a certificate cannot be asked for. */
    public const DOMAIN = 'domain';

    /** No address is set for Let's Encrypt to write to. */
    public const EMAIL = 'email';

    /** The last request about this game has not been answered yet. */
    public const BUSY = 'busy';

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct(trim("The request about the domain was refused ({$reason}) {$detail}"));
    }
}
