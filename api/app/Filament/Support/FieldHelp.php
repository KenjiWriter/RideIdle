<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;

/**
 * The one catalog of help for the forms of the panel. A field is given an entry by its key (`vehicle.power_kw`, `part.stat.grip`, `rules./tracks/*\/length_m`): the part before
 * the first dot is the area, which is the file (`lang/{locale}/help/{area}.php`), and the rest is the entry in it. An entry has a `label` and a `help` that says what the field
 * changes in the game, and may have an `example`, a `range`, a `tip` (the longer why) and, for a field that is a choice, the `options` with a label and a line each.
 *
 * A form puts an entry on a field with `->helpKey('vehicle.power_kw')`; the studio takes its numbers' entries by the pattern of their pointer (`rules./tracks/*\/length_m`, where
 * the star is a track). A field that is given a key but has no entry in a language is told by the test of the catalog (`HelpCatalogTest`), so that a field that is added later
 * cannot ship without its description. The text is read when the form is drawn, in the language of the panel, and English stands in where a language has no entry.
 */
final class FieldHelp
{
    /** The pages' guides ("how this works") that every form with one has an entry for, in the area `guide`. */
    public const GUIDES = ['vehicle', 'part', 'world', 'tutorial', 'model', 'branding', 'vocabulary', 'studio', 'rules_text', 'game', 'wizard'];

    /** @var array<string, array<string, array<string, array<string, mixed>>>> the entries of an area by language */
    private static array $loaded = [];

    /** Makes `->helpKey()` a method of every field of the forms. */
    public static function register(): void
    {
        Field::macro('helpKey', function (string $key, array $replace = [], bool $options = true): Field {
            /** @var Field $this */
            return FieldHelp::apply($this, $key, $replace, options: $options);
        });
    }

    /**
     * Gives a field the label, the helper text and the tip of its entry (what a field has no entry for is left as the form made it), and marks it with its key. A word of
     * the entry that begins with a colon (`:min`) is put in place of what `$replace` has for it. A field that has a label of its own, as the boxes for the languages of a text
     * have, takes only the helper text (`$label` false), and one box of several takes it once (`$help` false for the others). A field that shows a line under each of its
     * options itself (a radio) leaves them out of the text (`$options` false).
     *
     * @param  array<string, scalar>  $replace
     */
    public static function apply(Field $field, string $key, array $replace = [], bool $label = true, bool $help = true, bool $options = true): Field
    {
        $field->extraAttributes(['data-help' => $key], merge: true);

        $entry = self::entry($key);

        if ($entry === null) {
            return $field;
        }

        if ($label) {
            $field->label(fn (): ?string => self::said(self::entry($key)['label'] ?? null, $replace));
        }

        if ($help) {
            $field->helperText(fn (): ?HtmlString => self::helper($key, $replace, $options));
        }

        if (isset($entry['tip'])) {
            $field->hintIcon('heroicon-m-information-circle', tooltip: fn (): ?string => self::said(self::entry($key)['tip'] ?? null, $replace));
        }

        return $field;
    }

    /**
     * The entry of a key in a language of the panel (the one that is in use when none is given), or in English when that language has none; null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public static function entry(string $key, ?string $locale = null): ?array
    {
        [$area, $name] = array_pad(explode('.', $key, 2), 2, '');
        $locale ??= app()->getLocale();

        return self::entries($area, $locale)[$name] ?? ($locale === 'en' ? null : (self::entries($area, 'en')[$name] ?? null));
    }

    /** The entry of a key in exactly this language, without the English that stands in for a missing one. */
    public static function own(string $key, string $locale): ?array
    {
        [$area, $name] = array_pad(explode('.', $key, 2), 2, '');

        return self::entries($area, $locale)[$name] ?? null;
    }

    /**
     * Every entry of an area in a language.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function entries(string $area, string $locale): array
    {
        return self::$loaded[$locale][$area] ??= self::read($area, $locale);
    }

    /**
     * The entry of a number of the rules, by the pattern of its pointer.
     *
     * @return array<string, mixed>|null
     */
    public static function forPattern(string $pattern): ?array
    {
        return $pattern === '' ? null : self::entry("rules.{$pattern}");
    }

    /**
     * The labels of the options of a choice, by value: the label of the entry where it has one, the value where it has not.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public static function options(string $key, array $values): array
    {
        $labels = [];

        foreach ($values as $value) {
            $labels[$value] = (string) (self::entry($key)['options'][$value]['label'] ?? $value);
        }

        return $labels;
    }

    /**
     * The text under a field: what it changes, an example, the range, and a line for each option.
     *
     * @param  array<string, scalar>  $replace
     */
    public static function helper(string $key, array $replace = [], bool $options = true): ?HtmlString
    {
        $entry = self::entry($key);

        return $entry === null ? null : self::html($entry, $replace, $options);
    }

    /**
     * The line of each option of a choice, by value, for a field that shows them beside the options.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public static function descriptions(string $key, array $values): array
    {
        $lines = [];

        foreach ($values as $value) {
            $lines[$value] = (string) (self::entry($key)['options'][$value]['help'] ?? '');
        }

        return $lines;
    }

    /**
     * The same, for an entry that is already in hand.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, scalar>  $replace
     */
    public static function html(array $entry, array $replace = [], bool $options = true): ?HtmlString
    {
        $lines = [];

        if (filled($entry['help'] ?? null)) {
            $lines[] = '<span>'.e((string) self::said($entry['help'], $replace)).'</span>';
        }

        foreach (['example' => 'admin.help.example', 'range' => 'admin.help.range'] as $field => $label) {
            if (filled($entry[$field] ?? null)) {
                $lines[] = '<span style="opacity:.8"><strong>'.e((string) __($label)).':</strong> '.e((string) self::said($entry[$field], $replace)).'</span>';
            }
        }

        $items = [];

        foreach ($options ? (array) ($entry['options'] ?? []) : [] as $value => $option) {
            if (filled($option['help'] ?? null)) {
                $items[] = '<li><strong>'.e((string) self::said($option['label'] ?? $value, $replace)).'</strong>: '.e((string) self::said($option['help'], $replace)).'</li>';
            }
        }

        if ($items !== []) {
            // A long list of options stays closed until it is asked for, so that a form with several choices stays short.
            $lines[] = '<details style="margin:.25rem 0 0"><summary style="cursor:pointer;font-weight:600">'.e((string) __('admin.help.options', ['count' => count($items)])).'</summary>'
                .'<ul style="margin:.25rem 0 0 1.1rem;list-style:disc">'.implode('', $items).'</ul></details>';
        }

        return $lines === [] ? null : new HtmlString('<div style="display:grid;gap:.25rem">'.implode('', $lines).'</div>');
    }

    /** The guide of a form: a block that is closed until it is opened, with what the form is for, the steps to take in it, and a worked example. */
    public static function guide(string $name): Section
    {
        return Section::make(fn (): string => (string) (self::entry("guide.{$name}")['label'] ?? __('admin.help.guide')))
            ->description(fn (): ?string => self::entry("guide.{$name}")['help'] ?? null)
            ->icon('heroicon-o-light-bulb')
            ->collapsible()
            ->collapsed()
            ->schema([Text::make(fn (): HtmlString => self::guideBody($name))]);
    }

    /** The steps and the example of a guide. */
    public static function guideBody(string $name): HtmlString
    {
        $entry = self::entry("guide.{$name}") ?? [];
        $html = '';

        if (filled($entry['steps'] ?? null)) {
            $html .= '<ol style="margin:0 0 .5rem 1.25rem;list-style:decimal;display:grid;gap:.25rem">'.implode('', array_map(fn (mixed $step): string => '<li>'.e((string) $step).'</li>', (array) $entry['steps'])).'</ol>';
        }

        if (filled($entry['example'] ?? null)) {
            $html .= '<p style="margin:0"><strong>'.e((string) __('admin.help.example')).':</strong> '.e((string) $entry['example']).'</p>';
        }

        return new HtmlString($html);
    }

    /** The keys that an area of a language has, for the test of the catalog. @return list<string> */
    public static function keysOf(string $area, string $locale): array
    {
        return array_keys(self::entries($area, $locale));
    }

    /** Forgets what was read (a test that writes a catalog reads it again). */
    public static function flush(): void
    {
        self::$loaded = [];
    }

    /**
     * A text of an entry with the words that begin with a colon put in place.
     *
     * @param  array<string, scalar>  $replace
     */
    private static function said(mixed $text, array $replace): ?string
    {
        if ($text === null) {
            return null;
        }

        $keys = array_map(static fn (string $word): string => ':'.$word, array_keys($replace));

        return strtr((string) $text, array_combine($keys, array_map('strval', array_values($replace))) ?: []);
    }

    /** @return array<string, array<string, mixed>> */
    private static function read(string $area, string $locale): array
    {
        // An area may be written in several files (`rules.php`, `rules.tracks.php`) that are read together, so that people who write it in parts do not meet in one file.
        $files = glob(lang_path("{$locale}/help/{$area}{,.*}.php"), GLOB_BRACE) ?: [];
        sort($files);
        $entries = [];

        foreach ($files as $file) {
            $data = require $file;

            if (is_array($data)) {
                $entries = [...$entries, ...(array) ($data['entries'] ?? [])];
            }
        }

        return $entries;
    }
}
