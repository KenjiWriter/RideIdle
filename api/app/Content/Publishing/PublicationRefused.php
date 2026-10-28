<?php

declare(strict_types=1);

namespace App\Content\Publishing;

use App\Content\ContentException;

/** A publication that cannot be asked for: the reason is a word the panel can say in its language. */
final class PublicationRefused extends ContentException
{
    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct("The pack cannot be made: {$reason}.");
    }
}
