<?php

declare(strict_types=1);

namespace App\Content\Vocabulary;

use App\Support\Locale;

/**
 * The texts that the server says to a driver about the world of the game (lang/<language>/game.php: the refusals of the rules, the rarities, the weather, the drives),
 * by the key a vocabulary names them with: `server.game.errors.no_run` is `__('game.errors.no_run')`. The groups of the framework (validation, passwords, the buttons
 * of the panel) are not the words of a world and are not here.
 */
final class ServerMessages
{
    /** What a key of the vocabulary starts with when it is a text of the server and not a message of the web app. */
    public const PREFIX = 'server.';

    /** The groups of the language files that say something about the world. */
    private const GROUPS = ['game'];

    /** @var array<string, array<string, string>> language => key => text */
    private array $texts = [];

    public function __construct(private readonly string $path) {}

    public static function fromConfig(): self
    {
        return new self(lang_path());
    }

    /**
     * Every text of the server, by key, in English.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->texts('en');
    }

    /** The text of a key in a language, or the English one when the language has none. */
    public function text(string $key, string $locale): ?string
    {
        return $this->texts($locale)[$key] ?? $this->texts('en')[$key] ?? null;
    }

    /**
     * The names that a text fills in: `:required` in 'Requires level :required'. The framework also fills in `:Required` and `:REQUIRED`, which are the same name.
     *
     * @return list<string>
     */
    public static function placeholders(string $text): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $text, $found);

        $names = array_values(array_unique(array_map('strtolower', $found[1])));
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

        $flat = [];

        foreach (self::GROUPS as $group) {
            $file = rtrim($this->path, '/\\').DIRECTORY_SEPARATOR.Locale::toLaravel($locale).DIRECTORY_SEPARATOR."{$group}.php";
            $lines = is_file($file) ? (static fn (): mixed => include $file)() : null;

            if (is_array($lines)) {
                self::flatten($lines, self::PREFIX.$group, $flat);
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $flat
     */
    private static function flatten(array $node, string $prefix, array &$flat): void
    {
        foreach ($node as $name => $value) {
            $key = "{$prefix}.{$name}";

            if (is_array($value)) {
                self::flatten($value, $key, $flat);
            } elseif (is_string($value)) {
                $flat[$key] = $value;
            }
        }
    }
}
