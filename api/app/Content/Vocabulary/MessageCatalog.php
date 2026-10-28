<?php

declare(strict_types=1);

namespace App\Content\Vocabulary;

/**
 * Everything a vocabulary can name: the messages of the web app (`slot.tires`) and the texts of the server (`server.game.errors.no_run`), each with its text in every
 * language, the names it fills in, and the area it is in. The web texts are the files of the web app, which an API on its own does not have; then none of them is
 * known (`available()` is false), and the texts of the server still are.
 */
final class MessageCatalog
{
    public function __construct(private readonly WebMessages $web, private readonly ServerMessages $server) {}

    public static function fromConfig(): self
    {
        return new self(WebMessages::fromConfig(), ServerMessages::fromConfig());
    }

    /** Whether the texts of the web app are there to ask. */
    public function available(): bool
    {
        return $this->web->available();
    }

    /**
     * Every message, by key, in English: those of the web app first, in the order of the app, and then those of the server.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return [...$this->web->all(), ...$this->server->all()];
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /** Whether a message of this key can be judged at all: a text of the server always can, a message of the web app only when its files are there. */
    public function canJudge(string $key): bool
    {
        return self::isServer($key) || $this->web->available();
    }

    /** The text of a message in a language, or the English one when it has none in that language. */
    public function text(string $key, string $locale): ?string
    {
        return self::isServer($key) ? $this->server->text($key, $locale) : $this->web->text($key, $locale);
    }

    /**
     * The names that a message fills in: `{amount}` in a message of the web app, `:amount` in a text of the server.
     *
     * @return list<string>
     */
    public static function placeholders(string $key, string $text): array
    {
        return self::isServer($key) ? ServerMessages::placeholders($text) : WebMessages::placeholders($text);
    }

    /** A name as the message writes it. */
    public static function written(string $key, string $name): string
    {
        return self::isServer($key) ? ":{$name}" : '{'.$name.'}';
    }

    public static function isServer(string $key): bool
    {
        return str_starts_with($key, ServerMessages::PREFIX);
    }

    /** The area of a message: the first word of the key of the web app (`slot`, `nav`), and the group of a text of the server (`server.game`). */
    public static function area(string $key): string
    {
        $parts = explode('.', $key);

        return self::isServer($key) ? implode('.', array_slice($parts, 0, 2)) : $parts[0];
    }
}
