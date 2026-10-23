<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RaceRun;

/** The outcome of a tow. `towed` is false when the car was not stopped or the driver could pay, so nothing was done. */
final readonly class Towed
{
    public function __construct(
        public RaceRun $run,
        public bool $towed,
    ) {
    }
}
