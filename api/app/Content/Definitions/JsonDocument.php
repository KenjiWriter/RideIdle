<?php

declare(strict_types=1);

namespace App\Content\Definitions;

use JsonException;

/**
 * A document of the content as arrays that can be changed and written back without losing anything: an empty object stays an object (it is `{}` in the file and
 * would be `[]` after an array went through `json_decode` and `json_encode`, which the schema refuses as the wrong type), and everything else is arrays, in the
 * order of the file. Used wherever a person edits a document that has such objects (the rules, the steps of the tutorial).
 */
final class JsonDocument
{
    /**
     * @return array<string, mixed>
     *
     * @throws JsonException when the text is not JSON
     */
    public static function decode(string $json): array
    {
        $document = self::arrays(json_decode($json, false, 512, JSON_THROW_ON_ERROR));

        return is_array($document) ? $document : throw new JsonException('The document is not a JSON object.');
    }

    /** @param  array<string, mixed>  $document */
    public static function encode(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** A value decoded as objects, as arrays, except the empty objects, which stay objects. */
    public static function arrays(mixed $value): mixed
    {
        if (is_object($value)) {
            return (array) $value === [] ? $value : array_map(self::arrays(...), (array) $value);
        }

        return is_array($value) ? array_map(self::arrays(...), $value) : $value;
    }
}
