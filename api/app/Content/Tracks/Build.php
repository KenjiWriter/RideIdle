<?php

declare(strict_types=1);

namespace App\Content\Tracks;

/** A car with parts on it: the thing the track simulator compares. */
final readonly class Build
{
    /**
     * @param  array<string, string>  $mounted  slugs of the parts, by slot; a slot that is not there has the factory part
     * @param  int  $partsPriceCents  what the parts cost together, to tell two builds that drive the same apart
     */
    public function __construct(public string $vehicle, public array $mounted = [], public int $partsPriceCents = 0) {}

    public function label(): string
    {
        return $this->mounted === [] ? "{$this->vehicle} (stock)" : $this->vehicle.' + '.implode(', ', $this->mounted);
    }
}
