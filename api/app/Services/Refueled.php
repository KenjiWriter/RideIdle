<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RaceRun;

/**
 * The outcome of a refuel. When the request was a repeat of one that was already done, nothing was
 * changed or charged now and `repeated` is true.
 */
final readonly class Refueled
{
    public function __construct(
        public RaceRun $run,
        public int $addedMl,
        public int $costCents,
        public bool $repeated,
    ) {
    }
}
