<?php

declare(strict_types=1);

namespace App\Content\Definitions;

use InvalidArgumentException;

/**
 * What the form of a car or of a part holds and the document it makes: the two directions between the state of the form (flat fields, the price in dollars, the
 * statistics of a part as a list of lines) and the document of the schema. The document is made in the order of the files of the pack, with only what the schema
 * allows for this kind of car or part, so that what the schema refuses is the mistake of the person, not of the form. Nothing here judges: that is for the schema.
 */
final class DefinitionForm
{
    public const LOCALES = ['en', 'pl', 'fr', 'de', 'pt-BR'];

    /**
     * The state of a form for a document.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function toState(string $kind, array $document): array
    {
        return match ($kind) {
            'vehicle' => self::vehicleState($document),
            'part' => self::partState($document),
            default => throw new InvalidArgumentException("There is no form for {$kind}."),
        };
    }

    /**
     * The document that a form makes.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function toDocument(string $kind, array $state): array
    {
        return match ($kind) {
            'vehicle' => self::vehicleDocument($state),
            'part' => self::partDocument($state),
            default => throw new InvalidArgumentException("There is no form for {$kind}."),
        };
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private static function vehicleState(array $document): array
    {
        $engine = $document['engine'] ?? [];

        return [
            'slug' => $document['slug'] ?? null,
            'name' => self::texts($document['name'] ?? []),
            'description' => self::texts($document['description'] ?? []),
            'class' => $document['class'] ?? null,
            'drivetrain' => $document['drivetrain'] ?? null,
            'powertrain' => $document['powertrain'] ?? 'ice',
            'engine_layout' => $engine['layout'] ?? null,
            'engine_displacement_l' => $engine['displacement_l'] ?? null,
            'engine_tune' => $engine['tune'] ?? null,
            'engine_motors' => $engine['motors'] ?? null,
            'power_kw' => $document['power_kw'] ?? null,
            'mass_kg' => $document['mass_kg'] ?? null,
            'top_speed_kmh' => $document['top_speed_kmh'] ?? null,
            'clearance' => $document['clearance'] ?? null,
            'cargo_kg' => $document['cargo_kg'] ?? null,
            'seats' => $document['seats'] ?? null,
            'tank_l' => $document['tank_l'] ?? null,
            'battery_kwh' => $document['battery_kwh'] ?? null,
            'max_charge_kw' => $document['max_charge_kw'] ?? null,
            'tier' => $document['tier'] ?? null,
            'min_player_level' => $document['min_player_level'] ?? null,
            'price' => isset($document['price_cents']) ? $document['price_cents'] / 100 : null,
            'sources' => $document['sources'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function vehicleDocument(array $state): array
    {
        $electric = ($state['powertrain'] ?? 'ice') === 'ev';
        $document = ['slug' => self::text($state['slug'] ?? null), 'name' => self::i18n($state['name'] ?? [])];

        $description = self::i18n($state['description'] ?? []);

        if ($description !== []) {
            $document['description'] = $description;
        }

        $document['class'] = self::text($state['class'] ?? null);
        $document['drivetrain'] = self::text($state['drivetrain'] ?? null);
        $document['powertrain'] = $electric ? 'ev' : 'ice';
        $document['engine'] = $electric
            ? ['motors' => self::number($state['engine_motors'] ?? null)]
            : ['layout' => self::text($state['engine_layout'] ?? null), 'displacement_l' => self::number($state['engine_displacement_l'] ?? null), 'tune' => self::number($state['engine_tune'] ?? null)];

        foreach (['power_kw', 'mass_kg', 'top_speed_kmh', 'clearance', 'cargo_kg', 'seats'] as $field) {
            $document[$field] = self::number($state[$field] ?? null);
        }

        foreach ($electric ? ['battery_kwh', 'max_charge_kw'] : ['tank_l'] as $field) {
            $document[$field] = self::number($state[$field] ?? null);
        }

        $document['tier'] = self::number($state['tier'] ?? null);
        $document['min_player_level'] = self::number($state['min_player_level'] ?? null);
        $document['price_cents'] = self::cents($state['price'] ?? null);
        $document['sources'] = array_values(array_filter((array) ($state['sources'] ?? []), is_string(...)));

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private static function partState(array $document): array
    {
        $stats = [];

        foreach ($document['stats'] ?? [] as $key => $value) {
            $stats[] = ['key' => $key, 'value' => $value];
        }

        return [
            'slug' => $document['slug'] ?? null,
            'name' => self::texts($document['name'] ?? []),
            'slot' => $document['slot'] ?? null,
            'tire_kind' => $document['tire_kind'] ?? null,
            'tier' => $document['tier'] ?? null,
            'price' => isset($document['price_cents']) ? $document['price_cents'] / 100 : null,
            'stats' => $stats,
            'engine_layout' => $document['engine']['layout'] ?? null,
            'engine_displacement_l' => $document['engine']['displacement_l'] ?? null,
            'requires_min_cylinders' => $document['requires']['min_cylinders'] ?? null,
            'requires_booster' => (bool) ($document['requires']['booster'] ?? false),
            'fits_powertrain' => $document['fits']['powertrain'] ?? null,
            'fits_classes' => $document['fits']['classes'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function partDocument(array $state): array
    {
        $slot = self::text($state['slot'] ?? null);
        $document = ['slug' => self::text($state['slug'] ?? null), 'name' => self::i18n($state['name'] ?? []), 'slot' => $slot];

        if ($slot === 'tires') {
            $document['tire_kind'] = self::text($state['tire_kind'] ?? null);
        }

        $document['tier'] = self::number($state['tier'] ?? null);
        $document['price_cents'] = self::cents($state['price'] ?? null);

        $stats = [];

        foreach ((array) ($state['stats'] ?? []) as $line) {
            $key = self::text($line['key'] ?? null);

            if ($key !== null) {
                $stats[$key] = self::number($line['value'] ?? null);
            }
        }

        $document['stats'] = $stats;

        if ($slot === 'engine') {
            $document['engine'] = ['layout' => self::text($state['engine_layout'] ?? null), 'displacement_l' => self::number($state['engine_displacement_l'] ?? null)];
        }

        $requires = [];

        if (($state['requires_min_cylinders'] ?? null) !== null && $state['requires_min_cylinders'] !== '') {
            $requires['min_cylinders'] = self::number($state['requires_min_cylinders']);
        }

        if (! empty($state['requires_booster'])) {
            $requires['booster'] = true;
        }

        if ($requires !== []) {
            $document['requires'] = $requires;
        }

        $fits = [];

        if (self::text($state['fits_powertrain'] ?? null) !== null) {
            $fits['powertrain'] = self::text($state['fits_powertrain']);
        }

        $classes = array_values(array_filter((array) ($state['fits_classes'] ?? []), is_string(...)));

        if ($classes !== []) {
            $fits['classes'] = $classes;
        }

        if ($fits !== []) {
            $document['fits'] = $fits;
        }

        return $document;
    }

    /**
     * The texts of a field by language, with every language of the form there so that the form has a box for each.
     *
     * @param  array<string, mixed>  $texts
     * @return array<string, string|null>
     */
    public static function texts(array $texts): array
    {
        $state = [];

        foreach (self::LOCALES as $locale) {
            $state[$locale] = isset($texts[$locale]) ? (string) $texts[$locale] : null;
        }

        return $state;
    }

    /**
     * The texts that were written, in the order of the languages: a language with nothing in its box is not in the document.
     *
     * @param  array<string, mixed>  $texts
     * @return array<string, string>
     */
    public static function i18n(array $texts): array
    {
        $document = [];

        foreach (self::LOCALES as $locale) {
            $text = self::text($texts[$locale] ?? null);

            if ($text !== null) {
                $document[$locale] = $text;
            }
        }

        return $document;
    }

    /** A text without the white space round it, or null when there is nothing in it. */
    public static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * A number from what a box held: an integer when it is a whole number written without a point, a float otherwise, and what is not a number as it was, so
     * that the schema says what is wrong with it.
     */
    public static function number(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        $text = str_replace(',', '.', $text);

        if (! is_numeric($text)) {
            return $text;
        }

        return preg_match('/^-?\d+$/', $text) === 1 ? (int) $text : (float) $text;
    }

    /** The price of the form, in dollars, as the cents of the document (a number that is not a whole number of cents is left for the schema). */
    public static function cents(mixed $dollars): mixed
    {
        $number = self::number($dollars);

        if (! is_int($number) && ! is_float($number)) {
            return $number;
        }

        return (int) round($number * 100);
    }
}
