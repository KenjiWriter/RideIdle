<?php

declare(strict_types=1);

namespace App\Content;

/** The content of a pack: its documents in memory, with the number the runs are frozen with while it is the one in use. */
final class PackContent extends ContentSource
{
    /** @param array<string, string> $documents the text of every document by key, the manifest as `pack` */
    public function __construct(private readonly string $number, private readonly array $documents) {}

    public function number(): string
    {
        return $this->number;
    }

    public function rulesetVersion(): string
    {
        return $this->number;
    }

    public function slugs(string $folder): array
    {
        $slugs = [];

        foreach (array_keys($this->documents) as $key) {
            if (str_starts_with($key, "{$folder}/")) {
                $slugs[] = substr($key, strlen($folder) + 1);
            }
        }

        sort($slugs);

        return $slugs;
    }

    public function raw(string $key): ?string
    {
        return $this->documents[$key] ?? null;
    }
}
