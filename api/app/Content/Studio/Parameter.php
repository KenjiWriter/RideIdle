<?php

declare(strict_types=1);

namespace App\Content\Studio;

/** One number of the rules, as the studio shows it: where it is, what it is called and counted in, what the schema allows, and what it is and what it was. */
final readonly class Parameter
{
    /**
     * @param  list<string|int>  $path  the keys from the top of the document to the number
     * @param  string  $group  where it is, in words (`workshop › equipment › tire machine › manual`)
     * @param  int|float  $value  as the document keeps it
     * @param  int|float|null  $live  the same number in the pack that is live (null when that pack has none there)
     * @param  int|float|null  $min  as the document keeps it
     * @param  int|float|null  $max  as the document keeps it
     * @param  string  $pattern  where it is in the document with a star for every name that is not the name of a property of the schema (a track, a rung, a place in a list): the same for
     *                           every track, so that one line of help serves them all
     */
    public function __construct(
        public array $path,
        public string $section,
        public string $group,
        public ?string $groupDescription,
        public string $key,
        public string $label,
        public ?string $description,
        public bool $integer,
        public Unit $unit,
        public int|float $value,
        public int|float|null $live,
        public int|float|null $min,
        public int|float|null $max,
        public string $pattern = '',
    ) {}

    /** The pointer of the number in the document (`/workshop/upgrade/base_time_ms`). */
    public function pointer(): string
    {
        return '/'.implode('/', array_map(static fn (string|int $key): string => str_replace(['~', '/'], ['~0', '~1'], (string) $key), $this->path));
    }

    public function display(): int|float
    {
        return $this->unit->display($this->value);
    }

    public function liveDisplay(): int|float|null
    {
        return $this->live === null ? null : $this->unit->display($this->live);
    }

    public function minDisplay(): int|float|null
    {
        return $this->min === null ? null : $this->unit->display($this->min);
    }

    public function maxDisplay(): int|float|null
    {
        return $this->max === null ? null : $this->unit->display($this->max);
    }

    /** Whether the number is not what the live pack has. */
    public function changed(): bool
    {
        return $this->live !== null && $this->live != $this->value;
    }
}
