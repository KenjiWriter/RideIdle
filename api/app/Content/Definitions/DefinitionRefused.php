<?php

declare(strict_types=1);

namespace App\Content\Definitions;

use App\Content\ContentException;

/** A change of a definition that its life does not allow: the reason is a word the panel can say in its language, with what it needs to say it. */
final class DefinitionRefused extends ContentException
{
    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct("The definition cannot be changed: {$reason}.");
    }
}
