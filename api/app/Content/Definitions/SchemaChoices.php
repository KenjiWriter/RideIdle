<?php

declare(strict_types=1);

namespace App\Content\Definitions;

/** The words that the schemas allow in a place, so that the forms offer what the schema accepts and nothing is written down twice. */
final class SchemaChoices
{
    /** @return list<string> the facts about a driver that start and end a step of the tutorial */
    public static function conditions(): array
    {
        return self::enum('tutorial.schema.json', ['$defs', 'condition', 'oneOf', 0, 'enum']);
    }

    /** @return list<string> the facts of the tutorial that take a number or a word after a colon (cash:450) */
    public static function conditionParameters(): array
    {
        $pattern = self::node('tutorial.schema.json', ['$defs', 'condition', 'oneOf', 1, 'pattern']);

        preg_match_all('/([a-z_]+):/', is_string($pattern) ? $pattern : '', $found);

        return $found[1];
    }

    /** @return list<string> the kinds of job of the workshop that a step of the tutorial can make short and free */
    public static function arrangedJobs(): array
    {
        return array_values(array_filter(self::enum('tutorial.schema.json', ['properties', 'steps', 'items', 'properties', 'arranges', 'items', 'enum']), is_string(...)));
    }

    /** @return list<string> what the screen highlights while a step waits (without the step that needs no screen) */
    public static function targets(): array
    {
        return array_values(array_filter(self::enum('tutorial.schema.json', ['properties', 'steps', 'items', 'properties', 'target', 'enum']), is_string(...)));
    }

    /** @return list<string> the statistics of a car that a track can ask for */
    public static function stats(): array
    {
        return self::enum('rules.schema.json', ['$defs', 'stat', 'enum']);
    }

    /**
     * @param  list<string|int>  $path
     * @return list<mixed>
     */
    private static function enum(string $schema, array $path): array
    {
        $node = self::node($schema, $path);

        return is_array($node) ? array_values($node) : [];
    }

    /** @param  list<string|int>  $path */
    private static function node(string $schema, array $path): mixed
    {
        $node = json_decode((string) file_get_contents(config('ride.content_path').'/schema/'.$schema), true, 512, JSON_THROW_ON_ERROR);

        foreach ($path as $key) {
            $node = $node[$key] ?? [];
        }

        return $node;
    }
}
