<?php

declare(strict_types=1);

namespace App\Content\Publishing;

use App\Content\ContentException;
use App\Content\ContentSource;
use App\Content\PackContent;
use JsonException;
use stdClass;

/**
 * A pack as one file that can travel: the manifest and every document as JSON objects, with the number of the pack and the checksum of its content. The pack of one
 * environment goes to another through it (the panel downloads and uploads it) without a folder, and the checksum says that what arrived is what left: a bundle that
 * was changed on the way is not taken.
 */
final class ContentBundle
{
    /** The version of the shape of the file, which says nothing about the schemas of the documents in it: they are checked when the pack is made. */
    public const FORMAT = 1;

    private const KEY = '/^(vehicles|parts|rules|tables)\/[a-z0-9]+(-[a-z0-9]+)*$/';

    private const NUMBER = '/^[0-9]{4}\.[0-9]{1,2}\.[0-9]+$/';

    /** The pack of a source as the text of a bundle, under the number. */
    public function export(ContentSource $source, string $number): string
    {
        $manifest = $this->decode((string) $source->raw('pack'), 'the manifest');
        $manifest->version = $number;
        unset($manifest->checksum);

        $documents = new stdClass;

        foreach ($source->keys() as $key) {
            $documents->{$key} = $this->decode((string) $source->raw($key), $key);
        }

        return json_encode([
            'bundle' => self::FORMAT,
            'number' => $number,
            'checksum' => $source->checksum(),
            'pack' => $manifest,
            'documents' => $documents,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * The content that a bundle holds.
     *
     * @throws ContentException when the text is not a bundle, it holds something that is not a document of a pack, or its content is not the one it says
     */
    public function read(string $json): PackContent
    {
        try {
            $bundle = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $problem) {
            throw new ContentException("The file is not JSON: {$problem->getMessage()}", $problem);
        }

        if (! $bundle instanceof stdClass || ($bundle->bundle ?? null) !== self::FORMAT) {
            throw new ContentException('The file is not a bundle of a pack of this version of the game.');
        }

        $number = $bundle->number ?? null;

        if (! is_string($number) || preg_match(self::NUMBER, $number) !== 1) {
            throw new ContentException('The number of the pack in the bundle is not a number (year.month.n).');
        }

        if (! ($bundle->pack ?? null) instanceof stdClass || ! ($bundle->documents ?? null) instanceof stdClass) {
            throw new ContentException('The bundle has no manifest or no documents.');
        }

        $documents = ['pack' => $this->encode($bundle->pack)];

        foreach ((array) $bundle->documents as $key => $document) {
            if (preg_match(self::KEY, (string) $key) !== 1) {
                throw new ContentException("The bundle names a document that a pack does not have: {$key}.");
            }

            if (! $document instanceof stdClass) {
                throw new ContentException("The document {$key} of the bundle is not a JSON object.");
            }

            $documents[(string) $key] = $this->encode($document);
        }

        $content = new PackContent($number, $documents);

        if (($bundle->checksum ?? null) !== $content->checksum()) {
            throw new ContentException('The checksum of the bundle is not the one of its content: it was changed after it was made.');
        }

        return $content;
    }

    private function decode(string $json, string $what): stdClass
    {
        try {
            $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $problem) {
            throw new ContentException("{$what} is not JSON: {$problem->getMessage()}", $problem);
        }

        return $value instanceof stdClass ? $value : throw new ContentException("{$what} is not a JSON object.");
    }

    private function encode(stdClass $document): string
    {
        return json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
