<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Where a driver is in the tutorial: the step that waits for them (null when no step is to be shown), how many are
 * done, and what the steps that were completed by this very call paid.
 */
final readonly class TutorialState
{
    public const IN_PROGRESS = 'in_progress';

    public const FINISHED = 'finished';

    public const SKIPPED = 'skipped';

    /**
     * @param  array<string, mixed>|null  $current  the definition of the step to show, with `acknowledged`
     * @param  list<array{key: string, cash_cents: int, car_xp: int, xp: int, part: string|null, garage: string|null}>  $rewarded
     * @param  array<string, mixed>|null  $goal  the step the driver is to do next, started or not, with its number, whether it still `waits` for its trigger,
     *                                           and the names of the part and the garage it will pay: null when the tutorial is over or was skipped
     */
    public function __construct(
        public string $status,
        public int $total,
        public int $done,
        public ?array $current,
        public array $rewarded,
        public ?array $goal = null,
    ) {}
}
