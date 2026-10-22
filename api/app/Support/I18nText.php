<?php

declare(strict_types=1);

namespace App\Support;

use JsonSerializable;
use Stringable;

/**
 * Tekst w wielu językach (nazwy i opisy definicji z paczek treści).
 * Klucze to kody obsługiwanych języków: pl, en, fr, de, pt-BR.
 */
final readonly class I18nText implements JsonSerializable, Stringable
{
    /** @param array<string, string> $translations */
    private function __construct(private array $translations)
    {
    }

    /** @param array<string, string|null>|string|null $value */
    public static function from(array|string|null $value): self
    {
        if ($value === null) {
            return new self([]);
        }

        if (is_string($value)) {
            return new self([Locale::default() => $value]);
        }

        $translations = [];

        foreach ($value as $code => $text) {
            $locale = Locale::normalize((string) $code);

            if ($locale !== null && is_string($text) && trim($text) !== '') {
                $translations[$locale] = $text;
            }
        }

        return new self($translations);
    }

    /**
     * Tłumaczenie na język, a gdy go brak: język domyślny, potem kolejność z
     * config('ride.content_fallbacks'), na końcu pierwszy dostępny tekst.
     */
    public function for(?string $locale = null): string
    {
        $locale = Locale::normalize($locale ?? app()->getLocale()) ?? Locale::default();

        foreach ([$locale, ...(array) config('ride.content_fallbacks')] as $code) {
            if (isset($this->translations[$code])) {
                return $this->translations[$code];
            }
        }

        $available = array_values($this->translations);

        return $available[0] ?? '';
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->translations;
    }

    /** @return list<string> obsługiwane języki bez tłumaczenia */
    public function missing(): array
    {
        return array_values(array_diff(Locale::supported(), array_keys($this->translations)));
    }

    /** Czy są wszystkie języki wymagane do wdrożenia paczki treści. */
    public function isComplete(): bool
    {
        foreach ((array) config('ride.required_content_locales') as $code) {
            if (! isset($this->translations[$code])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->translations;
    }

    public function __toString(): string
    {
        return $this->for();
    }
}
