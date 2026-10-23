<?php

declare(strict_types=1);

namespace App\Domain\Economy;

/**
 * Sets the numbers the game runs on against the ones the anchors give (Systemy VI, point 19, layer 1): the lap, the
 * fuel and the reward of the first rung, the experience of a lap, and the price of every part of the pack. A number is
 * in order when it is within the tolerance of the ruleset of the one the anchors give.
 */
final class RulesConsistency
{
    private const HOUR_MS = 3_600_000;

    /**
     * @param  array{lap_ms: int, fuel_ml: int, fuel_price_cents: int, reward_cents: int, xp_per_lap: int, parts: list<array{slug: string, tier: int, slot: string, price_cents: int}>}  $game  the numbers of the first track and of the pack
     * @return list<Finding>
     */
    public function check(EconomyRules $economy, array $game): array
    {
        $findings = [
            $this->compare($economy, 'lap time of the first track', self::HOUR_MS / $economy->lapsPerHour(0), $game['lap_ms'], 'ms'),
            $this->compare($economy, 'fuel per lap', $economy->fuelLitersPerLap(0), $game['fuel_ml'] / 1000, 'l'),
            $this->compare($economy, 'price of a liter of fuel', $economy->fuelPrice(), $game['fuel_price_cents'] / 100, '$'),
            $this->compare($economy, 'reward per lap', $economy->grossPerLap(0), $game['reward_cents'] / 100, '$'),
            $this->compare($economy, 'experience per lap', $economy->xpPerLap(0), $game['xp_per_lap'], 'XP'),
        ];

        foreach ($game['parts'] as $part) {
            $findings[] = $this->compare($economy, "price of {$part['slug']}", $economy->partPrice($part['tier'], $part['slot']), $part['price_cents'] / 100, '$');
        }

        return $findings;
    }

    private function compare(EconomyRules $economy, string $subject, ?float $expected, float $actual, string $unit): Finding
    {
        $ok = $expected !== null && $expected > 0 && abs($actual - $expected) / $expected <= $economy->consistencyTolerance();

        return new Finding($subject, $expected, $actual, $unit, $ok);
    }
}
