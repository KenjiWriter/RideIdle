<?php

declare(strict_types=1);

namespace App\Content;

/** A file that the game does not take as a picture: the reason is a word the panel can say in its language, with what it needs to say it. */
final class ImageRefused extends ContentException
{
    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct("The file is not taken as a picture: {$reason}.");
    }
}
