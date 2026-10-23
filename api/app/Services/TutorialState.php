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
     * @param  list<array{key: string, cash_cents: int, car_xp: int}>  $rewarded
     */
    public function __construct(
        public string $status,
        public int $total,
        public int $done,
        public ?array $current,
        public array $rewarded,
    ) {}
}
