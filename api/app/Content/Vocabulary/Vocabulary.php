<?php

declare(strict_types=1);

namespace App\Content\Vocabulary;

use App\Content\ContentSource;
use App\Support\Locale;

/**
 * The vocabulary of a pack (content/rules/vocabulary.json): the messages of the web app that the pack says in its own words. A pack that has no such document, or
 * an empty one, changes nothing, and the app speaks with its own texts.
 */
final class Vocabulary
{
    /** @param array<string, array<string, string>> $messages key => language => text */
    private function __construct(private readonly string $version, private readonly array $messages) {}

    public static function fromConfig(): self
    {
        return self::fromSource(app(ContentSource::class));
    }

    public static function fromSource(ContentSource $source): self
    {
        if (! $source->has('rules/vocabulary')) {
            return new self('', []);
        }

        $document = $source->document('rules/vocabulary');

        return new self($source->has('pack') ? (string) ($source->manifest()['version'] ?? '') : '', $document['messages'] ?? []);
    }

    /** The number of the pack that the words are from: what a copy of them is called. */
    public function number(): string
    {
        return $this->version;
    }

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    /**
     * What each language of the game says, key by key: the text of the pack in that language, or its English text when it has none in that language (a French
     * driver of a game about ships does not read 'Tires' beside the 'Hull' of the others).
     *
     * @return array<string, array<string, string>> language => key => text
     */
    public function byLocale(): array
    {
        $byLocale = [];

        foreach (Locale::supported() as $locale) {
            $byLocale[$locale] = [];

            foreach ($this->messages as $key => $texts) {
                $text = $texts[$locale] ?? $texts['en'] ?? null;

                if ($text !== null) {
                    $byLocale[$locale][$key] = $text;
                }
            }
        }

        return $byLocale;
    }
}
