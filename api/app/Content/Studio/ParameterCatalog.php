<?php

declare(strict_types=1);

namespace App\Content\Studio;

/**
 * Every number of the rules, found by walking the document of the rules together with its schema: where it is, what the schema allows of it (the limits, what it is for),
 * what it is counted in (`Unit`), and what the pack that is live has there. The studio builds its forms from this, so a number that the rules get tomorrow is in the
 * studio tomorrow, with the limits the schema gives it, and no page has to be written for it.
 *
 * What the schema does not know (a key it has no place for) is not a parameter, and neither are texts, switches and choices: only numbers.
 */
final class ParameterCatalog
{
    /** The numbers at the top of the document that are not rules: the version of the schema. */
    private const NOT_RULES = ['schema', 'version'];

    /**
     * @param  array<string, mixed>  $schema  the schema of the rules
     * @param  array<string, mixed>  $common  the schema that the others point into (`common.schema.json`)
     */
    public function __construct(private readonly array $schema, private readonly array $common = []) {}

    /** The catalog of the game: the schemas that ship with it. */
    public static function ofTheGame(): self
    {
        $directory = rtrim((string) config('ride.content_path'), '/\\').'/schema';

        return new self(
            json_decode((string) file_get_contents("{$directory}/rules.schema.json"), true, 512, JSON_THROW_ON_ERROR),
            json_decode((string) file_get_contents("{$directory}/common.schema.json"), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The sections of the document (its top level) that have numbers, with how many, in the order of the document.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, int>
     */
    public function sections(array $document): array
    {
        $sections = [];

        foreach (array_keys($document) as $section) {
            $count = count($this->parameters($document, (string) $section));

            if ($count > 0) {
                $sections[(string) $section] = $count;
            }
        }

        return $sections;
    }

    /**
     * The numbers of a section, in the order of the document.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>|null  $live  the same document in the pack that is live
     * @return list<Parameter>
     */
    public function parameters(array $document, string $section, ?array $live = null): array
    {
        $schema = $this->schema['properties'][$section] ?? null;

        if (in_array($section, self::NOT_RULES, true) || ! array_key_exists($section, $document) || $schema === null) {
            return [];
        }

        $found = [];
        $this->walk($schema, $document[$section], $live[$section] ?? null, [$section], [$section], [$this->humanize($section)], $section, $section, false, null, $found);

        return $found;
    }

    /**
     * The document with the numbers of a section set to what a person wrote (in the unit they think in): the n-th number of `parameters()` gets the n-th value.
     * A number is kept inside the limits of the schema.
     *
     * @param  array<string, mixed>  $document
     * @param  array<int, int|float|string|null>  $values  by the position of the number in the section
     * @return array<string, mixed>
     */
    public function apply(array $document, string $section, array $values): array
    {
        foreach ($this->parameters($document, $section) as $position => $parameter) {
            $given = $values[$position] ?? null;

            if (! is_numeric($given)) {
                continue;
            }

            $raw = $parameter->unit->raw((float) $given, $parameter->integer);

            if ($parameter->min !== null) {
                $raw = max($raw, $parameter->min);
            }

            if ($parameter->max !== null) {
                $raw = min($raw, $parameter->max);
            }

            $at = &$document;

            foreach ($parameter->path as $key) {
                $at = &$at[$key];
            }

            $at = $parameter->integer ? (int) $raw : $raw;
            unset($at);
        }

        return $document;
    }

    /**
     * The numbers that are not what the live pack has, in the order of the document.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $live
     * @return list<Parameter>
     */
    public function differences(array $document, array $live): array
    {
        $changed = [];

        foreach (array_keys($document) as $section) {
            foreach ($this->parameters($document, (string) $section, $live) as $parameter) {
                if ($parameter->changed()) {
                    $changed[] = $parameter;
                }
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string|int>  $path
     * @param  list<string>  $pattern  the path with a star where the name is not one of a property of the schema
     * @param  list<string>  $trail  where it is, in words
     * @param  list<Parameter>  $found
     */
    private function walk(array $schema, mixed $value, mixed $live, array $path, array $pattern, array $trail, string $section, ?string $unitKey, bool $inList, ?string $parentDescription, array &$found): void
    {
        $schema = $this->resolve($schema);

        // An empty object of the document is an object, and has nothing in it.
        if (is_object($value)) {
            return;
        }

        if (is_array($value)) {
            $isList = ($schema['type'] ?? null) === 'array';
            $description = isset($schema['description']) ? (string) $schema['description'] : $parentDescription;

            foreach ($value as $key => $child) {
                $childSchema = $isList
                    ? ($schema['items'] ?? null)
                    : ($schema['properties'][$key] ?? (is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : null));

                if (! is_array($childSchema)) {
                    continue;
                }

                $name = $isList ? $this->nameOfItem($child, (int) $key) : $this->humanize((string) $key);

                $this->walk(
                    $childSchema,
                    $child,
                    is_array($live) ? ($live[$key] ?? null) : null,
                    [...$path, $key],
                    [...$pattern, $isList || ! isset($schema['properties'][$key]) ? '*' : (string) $key],
                    is_array($child) || is_object($child) ? [...$trail, $name] : $trail,
                    $section,
                    $isList || is_int($key) ? $unitKey : (string) $key,
                    $isList,
                    $description,
                    $found,
                );
            }

            return;
        }

        if (is_bool($value) || ! is_numeric($value)) {
            return;
        }

        $key = (string) end($path);
        // A number in a list, or in a map that is keyed by numbers (the tiers), is named and counted by the key above it.
        $named = $unitKey !== null && is_int(end($path));
        $integer = ! in_array($schema['type'] ?? 'integer', ['number', ['number']], true) && is_int($value);

        $found[] = new Parameter(
            path: $path,
            section: $section,
            group: implode(' › ', $trail),
            groupDescription: $parentDescription,
            key: $key,
            label: $named ? $this->humanize($unitKey).' '.($inList ? ((int) $key) + 1 : $key) : $this->humanize($key),
            description: isset($schema['description']) ? (string) $schema['description'] : null,
            integer: $integer,
            unit: Unit::of($named ? $unitKey : $key),
            value: $value,
            live: is_numeric($live) && ! is_bool($live) ? $live : null,
            min: isset($schema['minimum']) && is_numeric($schema['minimum']) ? $schema['minimum'] : null,
            max: isset($schema['maximum']) && is_numeric($schema['maximum']) ? $schema['maximum'] : null,
            pattern: '/'.implode('/', $pattern),
        );
    }

    /**
     * The schema a `$ref` points at (inside the schema of the rules, or inside the common one).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function resolve(array $schema): array
    {
        for ($hops = 0; isset($schema['$ref']) && $hops < 8; $hops++) {
            [$file, $pointer] = array_pad(explode('#', (string) $schema['$ref'], 2), 2, '');
            $node = $file === '' ? $this->schema : $this->common;

            foreach (array_filter(explode('/', $pointer), static fn (string $part): bool => $part !== '') as $part) {
                $node = $node[$part] ?? [];
            }

            $own = $schema;
            unset($own['$ref']);
            // What the property says of itself (its own description) wins over what the definition it points at says.
            $schema = [...(is_array($node) ? $node : []), ...$own];
        }

        return $schema;
    }

    /** What an item of a list is called: its own `key` or `name` when it has one (`manual`, `automatic`), its place otherwise. */
    private function nameOfItem(mixed $item, int $index): string
    {
        if (is_array($item)) {
            foreach (['key', 'name', 'slug'] as $name) {
                if (isset($item[$name]) && is_string($item[$name])) {
                    return $this->humanize($item[$name]);
                }
            }
        }

        return '#'.($index + 1);
    }

    private function humanize(string $key): string
    {
        // The unit is said by the field, so the word that names it is left out of the name.
        $name = (string) preg_replace('/(^|_)(ppm|ms|cents|ml)(?=_|$)/', '', $key);
        $words = trim(str_replace(['_', '-'], ' ', $name));

        return ucfirst($words === '' ? str_replace('_', ' ', $key) : $words);
    }
}
