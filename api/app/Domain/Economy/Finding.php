<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * A number of the rules of the game set against the one the anchors give. `expected` is null when the anchors say
 * nothing about it (a tier or a slot they do not know), which is a finding too.
 */
final readonly class Finding
{
    public function __construct(
        public string $subject,
        public ?float $expected,
        public float $actual,
        public string $unit,
        public bool $ok,
    ) {}

    /** How far the number of the game is from the one of the anchors, as a share of the latter. */
    public function deviation(): ?float
    {
        return $this->expected === null || $this->expected == 0.0 ? null : ($this->actual - $this->expected) / $this->expected;
    }
}
