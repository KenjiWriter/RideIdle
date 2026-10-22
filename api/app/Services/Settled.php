<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Settlement\SettlementResult;
use App\Models\Settlement;

/** The outcome of settling a run: the pure result and the row that was written (null when nothing happened). */
final readonly class Settled
{
    public function __construct(public SettlementResult $result, public ?Settlement $settlement)
    {
    }

    public function changedAnything(): bool
    {
        return $this->settlement !== null;
    }
}
