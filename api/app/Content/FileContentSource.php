<?php

declare(strict_types=1);

namespace App\Content;

/**
 * The content of the repository: the `content/` tree on disk, read when it is asked for and not before. This is the source of the tests and of a machine that
 * has no pack in its database; the version the runs are frozen with is the one of the configuration (`ride.ruleset_version`).
 */
final class FileContentSource extends ContentSource
{
    private const SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function __construct(private readonly string $root, private readonly string $pack = 'core') {}

    public function root(): string
    {
        return rtrim($this->root, '/\\');
    }

    public function rulesetVersion(): string
    {
        return (string) config('ride.ruleset_version');
    }

    public function slugs(string $folder): array
    {
        $directory = $this->directory($folder);

        if ($directory === null) {
            return [];
        }

        $slugs = array_map(static fn (string $file): string => basename($file, '.json'), glob("{$directory}/*.json") ?: []);
        sort($slugs);

        return $slugs;
    }

    public function raw(string $key): ?string
    {
        $path = $this->path($key);

        return $path !== null && is_file($path) ? (string) file_get_contents($path) : null;
    }

    private function directory(string $folder): ?string
    {
        return match ($folder) {
            'vehicles', 'parts' => "{$this->root()}/packs/{$this->pack}/{$folder}",
            'rules', 'tables' => "{$this->root()}/{$folder}",
            default => null,
        };
    }

    private function path(string $key): ?string
    {
        if ($key === 'pack') {
            return "{$this->root()}/packs/{$this->pack}/pack.json";
        }

        [$folder, $slug] = array_pad(explode('/', $key, 2), 2, '');
        $directory = $this->directory($folder);

        // The name goes into a path, so only a slug is let through.
        return $directory !== null && preg_match(self::SLUG, $slug) === 1 ? "{$directory}/{$slug}.json" : null;
    }
}
