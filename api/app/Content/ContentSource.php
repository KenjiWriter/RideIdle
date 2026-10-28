<?php

declare(strict_types=1);

namespace App\Content;

use JsonException;

/**
 * Where the content of the game is read from: a set of JSON documents named by keys, in the folders of the `content/` tree.
 *
 *   pack                  the manifest (packs/<name>/pack.json)
 *   vehicles/<slug>       a car   (packs/<name>/vehicles/<slug>.json)
 *   parts/<slug>          a part  (packs/<name>/parts/<slug>.json)
 *   rules/<name>          starter (the rules of the game), tutorial (its steps) and economy (the anchors of the model)
 *   tables/<name>         the tables that commands make from the rest (bot profiles, pace of the rarities, returns of the units, Elo)
 *
 * The files of the repository are one source (`FileContentSource`) and a pack in the database is another (`PackContent`): the game reads the same documents
 * whichever it is, and the documents keep the text they were written with, so that nothing depends on how a database would store JSON.
 */
abstract class ContentSource
{
    private const FOLDERS = ['vehicles', 'parts', 'rules', 'tables'];

    /** @var array<string, array<string, mixed>> */
    private array $decoded = [];

    /** What the runs are frozen with and compared by: a run whose version is not this one is frozen again at the next settlement. */
    abstract public function rulesetVersion(): string;

    /**
     * The names of the documents in a folder (vehicles, parts, rules or tables), sorted.
     *
     * @return list<string>
     */
    abstract public function slugs(string $folder): array;

    /** The text of a document, or null when there is none. */
    abstract public function raw(string $key): ?string;

    public function has(string $key): bool
    {
        return $this->raw($key) !== null;
    }

    /**
     * A document, decoded.
     *
     * @return array<string, mixed>
     *
     * @throws ContentException when there is no such document or it is not a JSON object
     */
    public function document(string $key): array
    {
        return $this->decoded[$key] ??= $this->decode($key);
    }

    /**
     * The documents of a folder by name, decoded.
     *
     * @return array<string, array<string, mixed>>
     */
    public function documents(string $folder): array
    {
        $documents = [];

        foreach ($this->slugs($folder) as $slug) {
            $documents[$slug] = $this->document("{$folder}/{$slug}");
        }

        return $documents;
    }

    /**
     * The keys of every document but the manifest, in the order the folders are listed above and by name within them.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach (self::FOLDERS as $folder) {
            foreach ($this->slugs($folder) as $slug) {
                $keys[] = "{$folder}/{$slug}";
            }
        }

        return $keys;
    }

    /**
     * The manifest.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return $this->document('pack');
    }

    /**
     * What identifies the content: the checksum of the manifest (without its version and its own checksum, which say which copy it is, not what it holds)
     * and of every document, in the order of the keys. The white space of a document does not count, the order of its keys and of its lists does.
     */
    public function checksum(): string
    {
        $manifest = $this->decodeObjects('pack');
        unset($manifest->version, $manifest->checksum);

        $lines = ['pack:'.hash('sha256', self::encode($manifest))];

        foreach ($this->keys() as $key) {
            $lines[] = "{$key}:".self::documentChecksum((string) $this->raw($key));
        }

        return hash('sha256', implode("\n", $lines));
    }

    /** The checksum of one document: the hash of its JSON written without white space. */
    public static function documentChecksum(string $raw): string
    {
        return hash('sha256', self::encode(json_decode($raw, false, 512, JSON_THROW_ON_ERROR)));
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decodeObjects(string $key): object
    {
        $object = json_decode($this->text($key), false, 512, JSON_THROW_ON_ERROR);

        return is_object($object) ? $object : throw new ContentException("The content document {$key} is not a JSON object.");
    }

    /** @return array<string, mixed> */
    private function decode(string $key): array
    {
        try {
            $document = json_decode($this->text($key), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $problem) {
            throw new ContentException("The content document {$key} is not JSON: {$problem->getMessage()}", $problem);
        }

        // A list is not a document (an empty object and an empty list both decode to an empty array, and are let through).
        return is_array($document) && ($document === [] || ! array_is_list($document)) ? $document : throw new ContentException("The content document {$key} is not a JSON object.");
    }

    private function text(string $key): string
    {
        return $this->raw($key) ?? throw new ContentException("The content has no document {$key}.");
    }
}
