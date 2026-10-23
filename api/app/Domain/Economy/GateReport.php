<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/** What the pace gate found: every line, and whether the ruleset passes. */
final readonly class GateReport
{
    /** @param list<GateRow> $rows */
    public function __construct(public array $rows, public float $tolerance, public float $slackDays) {}

    public function passed(): bool
    {
        foreach ($this->rows as $row) {
            if (! $row->passed) {
                return false;
            }
        }

        return true;
    }

    /** @return list<GateRow> the lines that are out of the tolerance */
    public function failures(): array
    {
        return array_values(array_filter($this->rows, static fn (GateRow $row): bool => ! $row->passed));
    }
}
