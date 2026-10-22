<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Respekt, the rating of a driver: an Elo style number that rises with a win and falls with a loss.
 * Integers only; the expected score comes from the EloTable.
 */
final class Rating
{
    public const START = 1_000;

    /** A rating below this is a street driver, from here to the next limit a club driver, and so on. */
    private const DIVISIONS = [
        1_700 => 'elite',
        1_400 => 'regional',
        1_100 => 'club',
    ];

    /** K factor: ratings move fast for new drivers, slower for established ones and slowest at the top. */
    public static function kFactor(int $matchesPlayed, int $rating): int
    {
        return match (true) {
            $matchesPlayed < 20 => 40,
            $rating < 1_700 => 24,
            default => 16,
        };
    }

    /**
     * The change of the player's rating after one rated match, rounded half away from zero.
     * The rival's change is computed the same way with the roles swapped.
     */
    public static function delta(EloTable $table, int $k, bool $won, int $playerRating, int $rivalRating): int
    {
        $expected = $table->expectedPpm($rivalRating - $playerRating);
        $score = $won ? Ppm::ONE : 0;

        return Ppm::mul($k, $score - $expected);
    }

    /** Division name from a rating: street, club, regional or elite. */
    public static function division(int $rating): string
    {
        foreach (self::DIVISIONS as $from => $name) {
            if ($rating >= $from) {
                return $name;
            }
        }

        return 'street';
    }

    /** Soft reset at the start of a season: 75% of the rating stays, 25% goes back to the start value. Rounds half up. */
    public static function seasonReset(int $rating): int
    {
        return intdiv(3 * $rating + self::START + 2, 4);
    }
}
