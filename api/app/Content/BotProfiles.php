<?php

declare(strict_types=1);

namespace App\Content;

use JsonException;

/**
 * The reference lap of every tier of car on every track, which the bots of the race drive around (`content/tables/bot-profiles.json`, made by
 * `BotProfileTable`): the dry lap of the quickest stock car of the tier. The table is read once per process. A tier or a track that it does not know has
 * no bots, so the cars of a pack that the table has not been made for yet race alone, as they did before.
 */
final class BotProfiles
{
    /** @var array<string, array<int, int>>|null */
    private ?array $tracks = null;

    public function __construct(private readonly string $path, private readonly ?ContentSource $source = null) {}

    public static function fromConfig(): self
    {
        return self::fromSource(app(ContentSource::class));
    }

    public static function fromSource(ContentSource $source): self
    {
        return new self('', $source);
    }

    /** The dry lap time, in ms, that the bots of a car of this tier on this track drive around, or null when the table has none. */
    public function referenceMs(string $track, int $tier): ?int
    {
        return $this->tracks()[$track][$tier] ?? null;
    }

    /** @return array<string, array<int, int>> */
    private function tracks(): array
    {
        if ($this->tracks !== null) {
            return $this->tracks;
        }

        $json = $this->source !== null ? $this->source->raw('tables/bot-profiles') : (is_file($this->path) ? (string) file_get_contents($this->path) : null);

        if ($json === null) {
            return $this->tracks = [];
        }

        try {
            $table = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->tracks = [];
        }

        return $this->tracks = is_array($table['tracks'] ?? null) ? $table['tracks'] : [];
    }
}
