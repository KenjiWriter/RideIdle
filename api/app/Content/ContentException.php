<?php

declare(strict_types=1);

namespace App\Content;

use RuntimeException;
use Throwable;

/** The content cannot be read or used: a document is missing or is not JSON, no pack is active, a pack is not valid. */
class ContentException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
