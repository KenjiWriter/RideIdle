<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RaceRun;

/** The outcome of a repair. `repaired` is false when the run had not broken down, so nothing was repaired. */
final readonly class Repaired
{
    public function __construct(
        public RaceRun $run,
        public bool $repaired,
    ) {
    }
}
