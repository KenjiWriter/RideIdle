<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalizacja kodów języka. Publiczny kod to BCP 47 (pl, en, fr, de, pt-BR),
 * a Laravel potrzebuje zapisu z podkreślnikiem (pt_BR).
 */
final class Locale
{
    /** @return list<string> */
    public static function supported(): array
    {
        return array_values(config('ride.locales'));
    }

    public static function default(): string
    {
        return (string) config('ride.default_locale');
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::supported(), true);
    }

    /**
     * Zamienia dowolny zapis (fr-CH, PT_br, br, EN) na obsługiwany kod albo null.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $key = strtolower(str_replace('_', '-', trim($input)));

        if ($key === '' || $key === '*') {
            return null;
        }

        $aliases = (array) config('ride.locale_aliases');

        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        foreach (self::supported() as $supported) {
            if (strtolower($supported) === $key) {
                return $supported;
            }
        }

        $primary = explode('-', $key)[0];

        if (isset($aliases[$primary])) {
            return $aliases[$primary];
        }

        foreach (self::supported() as $supported) {
            if (strtolower($supported) === $primary) {
                return $supported;
            }
        }

        return null;
    }

    /**
     * Wybiera pierwszy obsługiwany język z nagłówka Accept-Language,
     * z uwzględnieniem wag q.
     */
    public static function fromAcceptLanguage(?string $header): ?string
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $index => $part) {
            $segments = explode(';', trim($part));
            $tag = trim($segments[0]);
            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                $parameter = trim($parameter);

                if (str_starts_with($parameter, 'q=')) {
                    $quality = (float) substr($parameter, 2);
                }
            }

            if ($tag !== '' && $quality > 0) {
                $candidates[] = ['tag' => $tag, 'q' => $quality, 'i' => $index];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => [$b['q'], $a['i']] <=> [$a['q'], $b['i']]);

        foreach ($candidates as $candidate) {
            $locale = self::normalize($candidate['tag']);

            if ($locale !== null) {
                return $locale;
            }
        }

        return null;
    }

    /** Kod w formacie Laravela: pt-BR zamieniamy na pt_BR. */
    public static function toLaravel(string $locale): string
    {
        return str_replace('-', '_', $locale);
    }

    /** @return array<string, string> kod => nazwa własna */
    public static function names(): array
    {
        return (array) config('ride.locale_names');
    }
}
