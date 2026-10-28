<?php

declare(strict_types=1);

namespace App\Content;

/** A pack that does not pass the validation does not get in, and neither does one that is made live: the result says why. */
final class ContentPackInvalid extends ContentException
{
    public function __construct(public readonly PackValidationResult $result)
    {
        parent::__construct(sprintf('The content is not valid: %d error(s), %d warning(s).', count($result->errors()), count($result->warnings())));
    }
}
