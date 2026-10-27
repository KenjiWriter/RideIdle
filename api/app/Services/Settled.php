<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Settlement\SettlementResult;
use App\Models\Settlement;

/**
 * The outcome of settling a run: the pure result and the row that was written (null when nothing happened). A run whose parts wear is settled in several
 * passes when it drives past a stage of the wear or of the service; the result is then the sum of them, `settlement` is the last row, and `all` has every one.
 */
final readonly class Settled
{
    /** @param  list<Settlement>  $settlements  every row that was written, when there were several passes */
    public function __construct(public SettlementResult $result, public ?Settlement $settlement, public array $settlements = []) {}

    /**
     * Every row that was written by this settlement.
     *
     * @return list<Settlement>
     */
    public function all(): array
    {
        return $this->settlements !== [] ? $this->settlements : array_values(array_filter([$this->settlement]));
    }

    public function changedAnything(): bool
    {
        return $this->settlement !== null;
    }
}
