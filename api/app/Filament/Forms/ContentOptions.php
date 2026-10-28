<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Models\ContentDefinition;

/** What the selects of the world and tutorial forms offer that comes from the content as it is being worked on. */
final class ContentOptions
{
    /**
     * The parts that can be named, by slug, with their name. Parts that are archived are out of the packs, so they are not offered.
     *
     * @param  string|null  $slot  only the parts of this slot
     * @return array<string, string>
     */
    public static function parts(?string $slot = null): array
    {
        $options = [];

        $parts = ContentDefinition::query()->where('kind', ContentDefinition::KIND_PART)->where('status', '!=', ContentDefinition::STATUS_ARCHIVED)->with('latestVersion')->orderBy('slug')->get();

        foreach ($parts as $part) {
            if ($slot === null || ($part->workingDocument()['slot'] ?? null) === $slot) {
                $options[$part->slug] = "{$part->title(self::locale())} ({$part->slug})";
            }
        }

        return $options;
    }

    /**
     * The cars that can be named, by slug, with their name.
     *
     * @return array<string, string>
     */
    public static function cars(): array
    {
        $options = [];

        $cars = ContentDefinition::query()->where('kind', ContentDefinition::KIND_VEHICLE)->where('status', '!=', ContentDefinition::STATUS_ARCHIVED)->with('latestVersion')->orderBy('slug')->get();

        foreach ($cars as $car) {
            $options[$car->slug] = "{$car->title(self::locale())} ({$car->slug})";
        }

        return $options;
    }

    /**
     * The cities of the lines of a form, by slug, with their name.
     *
     * @param  array<int|string, mixed>|null  $regions  the state of the cities
     * @return array<string, string>
     */
    public static function cities(?array $regions): array
    {
        $options = [];

        foreach ($regions ?? [] as $region) {
            $slug = is_string($region['slug'] ?? null) ? trim($region['slug']) : '';

            if ($slug !== '') {
                $options[$slug] = self::name($region['name'] ?? [], $slug)." ({$slug})";
            }
        }

        return $options;
    }

    /**
     * The name in the language of the panel, in English when it has none, or the slug.
     *
     * @param  array<string, mixed>  $names
     */
    public static function name(mixed $names, string $slug): string
    {
        $names = is_array($names) ? $names : [];

        foreach ([self::locale(), 'en'] as $locale) {
            if (is_string($names[$locale] ?? null) && trim($names[$locale]) !== '') {
                return $names[$locale];
            }
        }

        return $slug;
    }

    private static function locale(): string
    {
        return str_replace('_', '-', app()->getLocale());
    }
}
