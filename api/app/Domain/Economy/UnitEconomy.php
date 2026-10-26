<?php

declare(strict_types=1);

namespace App\Domain\Economy;

use InvalidArgumentException;

/**
 * What the storage units of the game pay back, as the simulator needs it: for each kind of unit the level that opens it, its price, the mean
 * return of a sale of the find (a share of the price, with the guarantee of the game), and the sale value at fixed percentiles, from which the
 * chance that one sale is at least a given sum can be read for any sum. The kinds are in the order of their price, the cheapest first.
 *
 * It knows nothing of the pack: the table comes from the real domain (`App\Content\GameUnits`) and the prices and levels from the rules.
 */
final readonly class UnitEconomy
{
    /**
     * @param  array<string, array{unlock_level: int, price: float, mean_return: float, quantiles: list<array{p: float, dollars: float}>}>  $kinds  by key; the quantiles run from the lowest
     *                                                                                                                                              sale (p 0) to the highest (p 100), the percent of sales that are at most that much
     */
    public function __construct(private array $kinds)
    {
        foreach ($kinds as $key => $kind) {
            if ($kind['quantiles'] === []) {
                throw new InvalidArgumentException("The unit {$key} has no quantiles.");
            }
        }
    }

    /** @return list<string> the keys of the kinds, from the cheapest */
    public function kinds(): array
    {
        $keys = array_keys($this->kinds);

        usort($keys, fn (string $a, string $b): int => $this->kinds[$a]['price'] <=> $this->kinds[$b]['price']);

        return $keys;
    }

    public function unlockLevel(string $kind): int
    {
        return $this->kinds[$kind]['unlock_level'];
    }

    /** What the unit costs, in dollars. */
    public function price(string $kind): float
    {
        return $this->kinds[$kind]['price'];
    }

    /** What a sale of the find pays back on average, as a share of the price (0.91 is 91%). */
    public function meanReturn(string $kind): float
    {
        return $this->kinds[$kind]['mean_return'];
    }

    /**
     * The most expensive kind of unit that the level opens and that the budget pays for at least once, or null when there is none.
     */
    public function kindFor(int $level, float $budget): ?string
    {
        foreach (array_reverse($this->kinds()) as $kind) {
            if ($this->unlockLevel($kind) <= $level && $this->price($kind) <= $budget) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * The chance that the sale of the find of one unit is at least this many dollars, from the percentiles: the rank of the sum between two of
     * them is taken on a straight line. A sum at or below the lowest sale is certain, and one above the highest is out of reach.
     */
    public function chanceOfAtLeast(string $kind, float $dollars): float
    {
        $points = $this->kinds[$kind]['quantiles'];

        if ($dollars <= $points[0]['dollars']) {
            return 1.0;
        }

        for ($i = 0; $i < count($points) - 1; $i++) {
            $from = $points[$i];
            $to = $points[$i + 1];

            if ($dollars <= $to['dollars']) {
                $rank = $from['p'] + ($dollars - $from['dollars']) / ($to['dollars'] - $from['dollars']) * ($to['p'] - $from['p']);

                return max(0.0, 1.0 - $rank / 100);
            }
        }

        return 0.0;
    }
}
