<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Read access to the definitions of a content pack at run time (vehicles and parts).
 * The definitions are read once per process and looked up by slug, never by a path that came from a request. They come from a content source (the files of
 * the repository or the pack in the database), or from the folder of a pack when one is given.
 */
final class Catalog
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $vehicles = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $parts = null;

    public function __construct(private readonly string $packPath = '', private readonly ?ContentSource $source = null) {}

    public static function fromConfig(): self
    {
        return self::fromSource(app(ContentSource::class));
    }

    public static function fromSource(ContentSource $source): self
    {
        return new self('', $source);
    }

    /** @return array<string, mixed>|null */
    public function vehicle(string $slug): ?array
    {
        return $this->vehicles()[$slug] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function part(string $slug): ?array
    {
        return $this->parts()[$slug] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public function vehicles(): array
    {
        return $this->vehicles ??= $this->load('vehicles');
    }

    /** @return array<string, array<string, mixed>> */
    public function parts(): array
    {
        return $this->parts ??= $this->load('parts');
    }

    /**
     * The wrecks a new driver can start with.
     *
     * @return array<string, array<string, mixed>>
     */
    public function startingVehicles(): array
    {
        return array_filter($this->vehicles(), static fn (array $vehicle): bool => in_array('start', $vehicle['sources'], true));
    }

    /** @return array<string, array<string, mixed>> */
    private function load(string $folder): array
    {
        if ($this->source !== null) {
            $documents = $this->source->documents($folder);

            // A withdrawn car is still a car (the ones that players own run), but nothing offers it any more.
            if ($folder === 'vehicles') {
                foreach ($this->source->withdrawn() as $slug) {
                    if (isset($documents[$slug])) {
                        $documents[$slug]['sources'] = [];
                    }
                }
            }

            return $documents;
        }

        $files = glob("{$this->packPath}/{$folder}/*.json") ?: [];
        sort($files);

        $definitions = [];

        foreach ($files as $file) {
            $definition = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $definitions[$definition['slug']] = $definition;
        }

        return $definitions;
    }
}
