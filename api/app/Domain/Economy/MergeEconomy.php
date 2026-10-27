<?php

declare(strict_types=1);

namespace App\Domain\Economy;

use Closure;
use InvalidArgumentException;

/**
 * What merging two parts is worth in the economy of the simulator (Systemy I, point 3, and the lever "łączenie" of Systemy VI): two parts of one slot,
 * tier and rarity make one part of the next rarity, or fail, and both are lost. How many parts and how much cash a part of each rarity takes when it
 * is made from common ones, with the protection from bad luck (every failure raises the chance of the next attempt) and without it.
 * Pure: the chances come as a function of the rules of the game and the figures of the cost as shares of the price of the model.
 *
 * Exact, not drawn. The attempts at one step end at the first success, and the chance of the attempt after k failures is known, so the expected number of
 * attempts is the sum of the chances to have failed so far, with the tail summed in a closed form once the chance stops growing (the cap).
 *
 * The convention for the cash, which keeps every number in units of p, the price of one part of the model (the rules take the price of the model for
 * the fee and the scrap alike, whatever the rarity of the parts used):
 *  - a common part costs p (what a crate or the shop asks for it);
 *  - an attempt costs the fee (a share of p) and uses two parts of the rarity below, which are valued at what they cost to make;
 *  - a failed attempt gives back the scrap (a share of p).
 * So the cost of a part of rarity r is `attempts * (2 * cost(r - 1) + fee) - (attempts - 1) * scrap`.
 */
final readonly class MergeEconomy
{
    /** The rarities that merging makes, from the one above the common. */
    public const STEPS = ['uncommon', 'rare', 'epic', 'legendary'];

    /** More attempts than this means the chance never stops growing, which is a mistake of the function. */
    private const LIMIT = 10_000;

    /**
     * @param  Closure(string, int): int  $chancePpm  the chance in ppm of an attempt that makes this rarity, after this many failures since the last success: it never falls, and it is the same from the failure where it stops growing
     * @param  int  $feePpm  what an attempt costs, as a share of the price of the model
     * @param  int  $scrapPpm  what a failed attempt gives back, as a share of the price of the model
     */
    public function __construct(private Closure $chancePpm, public int $feePpm, public int $scrapPpm)
    {
        if ($feePpm < 0 || $scrapPpm < 0) {
            throw new InvalidArgumentException('The fee and the scrap of merging cannot be negative.');
        }

        foreach (self::STEPS as $rarity) {
            if ($this->chance($rarity, 0) < 1) {
                throw new InvalidArgumentException("An attempt that makes a {$rarity} part has no chance to succeed.");
            }
        }
    }

    /** The chance of an attempt that makes the rarity after this many failures, in ppm (800 000 is 80%). */
    public function chance(string $rarity, int $failures): int
    {
        if (! in_array($rarity, self::STEPS, true) || $failures < 0) {
            throw new InvalidArgumentException("Merging does not make {$rarity} after {$failures} failures.");
        }

        return ($this->chancePpm)($rarity, $failures);
    }

    /**
     * How many attempts it takes on average to make one part of the rarity, with the protection from bad luck or without it (the chance of the first
     * attempt for all of them).
     */
    public function attempts(string $rarity, bool $pity = true): float
    {
        $survive = 1.0;
        $expected = 0.0;

        for ($failures = 0; $failures < self::LIMIT; $failures++) {
            $chance = $this->chance($rarity, $pity ? $failures : 0);
            $constant = ! $pity || $chance === $this->chance($rarity, $failures + 1);

            if ($constant) {
                // The chance is the same from here on: the attempts that are left are a geometric sum.
                return $expected + $survive / ($chance / 1_000_000);
            }

            $expected += $survive;
            $survive *= 1 - $chance / 1_000_000;
        }

        throw new InvalidArgumentException("The chance of making a {$rarity} part never stops growing.");
    }

    /**
     * The table of the steps, from the common part to each rarity: the chance of the first attempt, the attempts of one step, and what a part of the
     * rarity takes in common parts and in cash (in units of p) when it is made all the way from common ones.
     *
     * @return list<array{rarity: string, chance: float, attempts: float, attempts_no_pity: float, commons: float, commons_no_pity: float, fees: float, scrap: float, cost: float}>
     */
    public function steps(): array
    {
        $fee = $this->feePpm / 1_000_000;
        $scrap = $this->scrapPpm / 1_000_000;
        $commons = 1.0;
        $commonsNoPity = 1.0;
        $fees = 0.0;
        $scraps = 0.0;
        $rows = [];

        foreach (self::STEPS as $rarity) {
            $attempts = $this->attempts($rarity);
            $attemptsNoPity = $this->attempts($rarity, pity: false);

            $commons *= 2 * $attempts;
            $commonsNoPity *= 2 * $attemptsNoPity;
            // The fees and the scrap of the step, and of all the attempts that made the parts used.
            $fees = $attempts * (2 * $fees + $fee);
            $scraps = $attempts * 2 * $scraps + ($attempts - 1) * $scrap;

            $rows[] = [
                'rarity' => $rarity,
                'chance' => $this->chance($rarity, 0) / 1_000_000,
                'attempts' => $attempts,
                'attempts_no_pity' => $attemptsNoPity,
                'commons' => $commons,
                'commons_no_pity' => $commonsNoPity,
                'fees' => $fees,
                'scrap' => $scraps,
                'cost' => $commons + $fees - $scraps,
            ];
        }

        return $rows;
    }

    /**
     * The steps next to what the crates ask for the same rarity: the crates it takes on average to get one part of the rarity or a better one, and
     * what they cost (a crate costs `$cratePrice` p). A crate gives parts of every rarity, so this is the cost of getting a part of the rarity from
     * crates alone, the parts of the other rarities that come with it left aside.
     *
     * @param  array<string, float>  $cratesToFirst  the mean number of crates to the first part of each rarity or a better one, by rarity
     * @param  float  $cratePrice  what a crate costs in p
     * @return list<array<string, float|string|bool>>
     */
    public function againstCrates(array $cratesToFirst, float $cratePrice): array
    {
        return array_map(function (array $step) use ($cratesToFirst, $cratePrice): array {
            $crates = $cratesToFirst[$step['rarity']] ?? throw new InvalidArgumentException("The crates have no mean for {$step['rarity']}.");
            $crateCost = $crates * $cratePrice;

            return [
                ...$step,
                'crates' => $crates,
                'crate_cost' => $crateCost,
                'ratio' => $step['cost'] / $crateCost,
                'cheaper' => $step['cost'] < $crateCost,
            ];
        }, $this->steps());
    }
}
