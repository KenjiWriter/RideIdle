<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use InvalidArgumentException;

/**
 * Splits the pool of a single-elimination tournament.
 *
 *   pool = floor(entry fees * 90%)            the rest is the 10% rake, a sink of the economy
 *   share of a place = floor(pool * basis points / 10 000)
 *   the few cents left over by rounding go to the winner
 *
 * The shares are in basis points (10 000 = the whole pool) and are paid to every
 * player in the group: one winner, one finalist, two semifinalists and so on.
 */
final class TournamentPayout
{
    public const RAKE_BASIS_POINTS = 1_000;

    private const WHOLE = 10_000;

    /**
     * Group definitions per bracket size: [stage, number of players, basis points for each].
     *
     * @var array<int, list<array{0: string, 1: int, 2: int}>>
     */
    private const SHARES = [
        8 => [['winner', 1, 5_000], ['finalist', 1, 2_500], ['semifinalist', 2, 1_250]],
        16 => [['winner', 1, 4_000], ['finalist', 1, 2_000], ['semifinalist', 2, 1_000], ['quarterfinalist', 4, 500]],
        32 => [['winner', 1, 3_500], ['finalist', 1, 1_700], ['semifinalist', 2, 900], ['quarterfinalist', 4, 450], ['round_of_16', 8, 150]],
    ];

    /** @return list<int> the bracket sizes with a payout table */
    public static function sizes(): array
    {
        return array_keys(self::SHARES);
    }

    public static function split(int $players, int $entryFeeCents): PayoutResult
    {
        if (! isset(self::SHARES[$players])) {
            throw new InvalidArgumentException("No payout table for {$players} players. Sizes: ".implode(', ', self::sizes()).'.');
        }

        if ($entryFeeCents <= 0) {
            throw new InvalidArgumentException('The entry fee must be positive.');
        }

        $total = $players * $entryFeeCents;
        $pool = intdiv($total * (self::WHOLE - self::RAKE_BASIS_POINTS), self::WHOLE);

        $groups = [];
        $paid = 0;

        foreach (self::SHARES[$players] as [$stage, $count, $basisPoints]) {
            $each = intdiv($pool * $basisPoints, self::WHOLE);
            $groups[] = ['stage' => $stage, 'count' => $count, 'each_cents' => $each];
            $paid += $count * $each;
        }

        return new PayoutResult($total, $pool, $total - $pool, $groups, $pool - $paid);
    }

    /** Basis points paid to the players of one bracket size; always 10 000. */
    public static function basisPointsPaid(int $players): int
    {
        return array_sum(array_map(static fn (array $group): int => $group[1] * $group[2], self::SHARES[$players] ?? []));
    }
}
