<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Account recovery code: 12 characters shown as XXXX-XXXX-XXXX.
 *
 * The alphabet has no 0, O, 1 or I, so a code copied by hand or read aloud is
 * hard to mistake. 32 symbols over 12 positions give 60 bits of entropy. Only
 * an HMAC of the code is stored, so a leaked database does not reveal codes.
 */
final class RecoveryCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 12;

    /** A new code in display form, for example 7K2M-Q9XP-4HTD. */
    public static function generate(): string
    {
        $characters = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($characters, 4));
    }

    /**
     * Strips spaces and dashes and upper-cases the input. Returns null when the
     * result cannot be a code, so such input never reaches the database.
     */
    public static function normalize(string $input): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');

        return preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', $normalized) === 1
            ? $normalized
            : null;
    }

    /** Keyed hash of a normalized code, safe to index and compare. */
    public static function hash(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, 'recovery-code:'.config('app.key'));
    }
}
