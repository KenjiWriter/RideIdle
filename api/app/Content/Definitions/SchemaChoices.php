<?php

declare(strict_types=1);

namespace App\Content\Definitions;

/** The words that the schemas allow in a place, so that the forms offer what the schema accepts and nothing is written down twice. */
final class SchemaChoices
{
    /** @return list<string> the facts about a driver that start and end a step of the tutorial */
    public static function conditions(): array
    {
        return self::enum('tutorial.schema.json', ['$defs', 'condition', 'enum']);
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
     * @param  list<string>  $path
     * @return list<mixed>
     */
    private static function enum(string $schema, array $path): array
    {
        $node = json_decode((string) file_get_contents(config('ride.content_path').'/schema/'.$schema), true, 512, JSON_THROW_ON_ERROR);

        foreach ($path as $key) {
            $node = $node[$key] ?? [];
        }

        return array_values($node);
    }
}
