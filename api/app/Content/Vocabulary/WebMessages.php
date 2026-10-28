<?php

declare(strict_types=1);

namespace App\Content\Vocabulary;

use App\Support\Locale;

/**
 * The texts that the web app brings for itself (web/src/i18n/locales/<language>.json), by the dotted key of the message (`slot.tires`). They are what a pack's
 * vocabulary replaces, so the panel shows them beside the words of the pack, and the validator knows which messages there are and which {names} each one fills in.
 * When the files are not there (an API without the web app next to it) the catalog is empty, and nothing is checked against it.
 */
final class WebMessages
{
    /** @var array<string, array<string, string>> language => key => text */
    private array $texts = [];

    public function __construct(private readonly string $path) {}

    public static function fromConfig(): self
    {
        return new self((string) config('ride.web_locales_path'));
    }

    /** Whether the texts of the app are there to ask. */
    public function available(): bool
    {
        return $this->texts('en') !== [];
    }

    /**
     * Every message of the app, by key, in English: the language that has all of them.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->texts('en');
    }

    public function has(string $key): bool
    {
        return isset($this->texts('en')[$key]);
    }

    /** The text of a message in a language, or the English one when the app has none in that language. */
    public function text(string $key, string $locale): ?string
    {
        return $this->texts($locale)[$key] ?? $this->texts('en')[$key] ?? null;
    }

    /**
     * The names that a message fills in: `{amount}` in 'Costs {amount}'.
     *
     * @return list<string>
     */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', $text, $found);

        $names = array_values(array_unique($found[1]));
        sort($names);

        return $names;
    }

    /** @return array<string, string> key => text */
    private function texts(string $locale): array
    {
        if (! isset($this->texts[$locale])) {
            $this->texts[$locale] = $this->read($locale);
        }

        return $this->texts[$locale];
    }

    /** @return array<string, string> */
    private function read(string $locale): array
    {
        if (! Locale::isSupported($locale)) {
            return [];
        }

        $file = rtrim($this->path, '/\\').DIRECTORY_SEPARATOR."{$locale}.json";
        $json = is_file($file) ? file_get_contents($file) : false;
        $tree = $json === false ? null : json_decode($json, true);

        if (! is_array($tree)) {
            return [];
        }

        $flat = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$flat): void {
            foreach ($node as $name => $value) {
                $key = $prefix === '' ? (string) $name : "{$prefix}.{$name}";

                if (is_array($value)) {
                    $walk($value, $key);
                } elseif (is_string($value)) {
                    $flat[$key] = $value;
                }
            }
        };
        $walk($tree, '');

        return $flat;
    }
}
