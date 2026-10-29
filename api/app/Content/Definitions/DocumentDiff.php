<?php

declare(strict_types=1);

namespace App\Content\Definitions;

/**
 * What differs between two documents of a definition: every value at its pointer (`/stats/grip`, `/name/pl`) that was added, taken away or changed. Lists are compared place by
 * place, so a list in which something moved up shows every place that holds something else; a number is the same number whether the document kept it as 10 or as 10.0.
 */
final class DocumentDiff
{
    public const ADDED = 'added';

    public const REMOVED = 'removed';

    public const CHANGED = 'changed';

    /**
     * @param  array<string, mixed>|null  $old  the document that is the base (null: there is none, and everything is added)
     * @param  array<string, mixed>  $new
     * @return list<array{pointer: string, type: string, from: mixed, to: mixed}>
     */
    public static function between(?array $old, array $new): array
    {
        $before = self::flatten($old ?? []);
        $after = self::flatten($new);
        $changes = [];

        foreach ($after as $pointer => $value) {
            if (! array_key_exists($pointer, $before)) {
                $changes[] = ['pointer' => $pointer, 'type' => self::ADDED, 'from' => null, 'to' => $value];
            } elseif (! self::same($before[$pointer], $value)) {
                $changes[] = ['pointer' => $pointer, 'type' => self::CHANGED, 'from' => $before[$pointer], 'to' => $value];
            }
        }

        foreach ($before as $pointer => $value) {
            if (! array_key_exists($pointer, $after)) {
                $changes[] = ['pointer' => $pointer, 'type' => self::REMOVED, 'from' => $value, 'to' => null];
            }
        }

        return $changes;
    }

    /**
     * Every value of a document by its pointer. An empty list or object is a value of its own.
     *
     * @param  array<mixed>  $document
     * @return array<string, mixed>
     */
    public static function flatten(array $document, string $prefix = ''): array
    {
        $flat = [];

        foreach ($document as $key => $value) {
            $pointer = $prefix.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);

            if (is_array($value) && $value !== []) {
                $flat += self::flatten($value, $pointer);
            } else {
                $flat[$pointer] = $value;
            }
        }

        return $flat;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return match (true) {
            // The empty object of a document is an object each time it is read: two of them are the same.
            is_object($a) && is_object($b) => $a == $b,
            is_numeric($a) && is_numeric($b) && ! is_string($a) && ! is_string($b) => $a == $b,
            default => $a === $b,
        };
    }
}
