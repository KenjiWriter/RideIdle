<?php

declare(strict_types=1);

namespace App\Domain\Run;

/**
 * Deterministic "random" numbers from a seed. The same seed, domain and index give the same
 * number on every machine, so a breakdown or the weather can be recomputed at any time.
 * The built-in generators (mt_rand, random_int) are never used for results.
 */
final class Rng
{
    /** A number from 0 to 999 999 (ppm): the first 32 bits of sha256 of "seed|domain|index", modulo one million. */
    public static function roll(string $seed, string $domain, int $index): int
    {
        return hexdec(substr(hash('sha256', "{$seed}|{$domain}|{$index}"), 0, 8)) % 1_000_000;
    }
}
