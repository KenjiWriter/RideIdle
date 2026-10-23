<?php

declare(strict_types=1);

namespace App\Domain\Economy;

use InvalidArgumentException;

/**
 * The anchors of the economy (content/rules/economy.json) and what follows from them: the profit of an hour of driving
 * at each rung of the ladder of cars, the reward of a lap, the cost of a step up, the experience a level takes.
 * The shape is guaranteed by the schema; this class checks only what the schema cannot, that the lists agree.
 *
 * The numbers are those of Systemy V and VI of the design, and a value is a float of dollars: this is a tool for
 * judging a balance, and nothing in it touches a balance of the game.
 */
final readonly class EconomyRules
{
    /** The most levels looked for, so that no experience can send a loop round for ever. */
    private const MAX_LEVEL = 1000;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, PlayerType>  $players
     */
    private function __construct(private array $data, private array $players) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $rungs = count($data['anchors']['ladder']);
        $players = [];

        foreach ($data['players'] as $key => $player) {
            $key = (string) $key;

            if (count($player['fleet']) < $rungs) {
                throw new InvalidArgumentException("The fleet of the player {$key} has no entry for each of the {$rungs} rungs.");
            }

            if (! isset($data['targets'][$key]) || count($data['targets'][$key]) !== $rungs - 1) {
                throw new InvalidArgumentException("The player {$key} needs a target day for each of the ".($rungs - 1).' cars after the first.');
            }

            $players[$key] = new PlayerType(
                $key,
                (string) $player['name'],
                (int) $player['visits_per_day'],
                (float) $player['max_hours_per_day'],
                (float) $player['activity'],
                array_map('floatval', array_values($player['fleet'])),
            );
        }

        return new self($data, $players);
    }

    public function version(): string
    {
        return $this->data['version'];
    }

    /** How many rungs the ladder of cars has, the repaired wreck being the first. */
    public function rungs(): int
    {
        return count($this->data['anchors']['ladder']);
    }

    public function rungName(int $rung): string
    {
        return $this->data['anchors']['ladder'][$rung]['rung'];
    }

    public function carValue(int $rung): float
    {
        return (float) $this->data['anchors']['ladder'][$rung]['car_value'];
    }

    public function minPlayerLevel(int $rung): int
    {
        return (int) $this->data['anchors']['ladder'][$rung]['min_player_level'];
    }

    public function lapsPerHour(int $rung): float
    {
        return (float) $this->data['anchors']['ladder'][$rung]['laps_per_hour'];
    }

    public function fuelLitersPerLap(int $rung): float
    {
        return (float) $this->data['anchors']['ladder'][$rung]['fuel_l_per_lap'];
    }

    public function offlineWindowHours(int $rung): float
    {
        return (float) $this->data['anchors']['ladder'][$rung]['offline_window_h'];
    }

    public function fuelPrice(): float
    {
        return (float) $this->data['model']['fuel_price'];
    }

    /** The net profit of an hour of driving at this rung, with the knob of the rewards turned: base * growth ^ rung. */
    public function netPerHour(int $rung): float
    {
        $anchor = $this->data['anchors']['net_per_hour'];

        return $anchor['base'] * $anchor['growth'] ** $rung * $this->data['knobs']['reward_mult'];
    }

    /** What a lap pays before the fuel is paid: the profit of a lap and the fuel it burns. */
    public function grossPerLap(int $rung): float
    {
        return $this->netPerHour($rung) / $this->lapsPerHour($rung) + $this->fuelLitersPerLap($rung) * $this->fuelPrice();
    }

    /** How many hours of driving a car of this rung needs to pay for itself. */
    public function paybackHours(int $rung): float
    {
        return $this->carValue($rung) / $this->netPerHour($rung);
    }

    /** What the step from this rung to the next costs: the next car, less what the old one is sold for. */
    public function stepCost(int $rung): float
    {
        return $this->carValue($rung + 1) - $this->data['model']['resale'] * $this->carValue($rung);
    }

    public function startCash(): float
    {
        return (float) $this->data['model']['start_cash'];
    }

    /** The experience that reaches a level: base * level ^ exponent. */
    public function xpToReach(int $level): float
    {
        $xp = $this->data['model']['xp'];

        return $xp['base'] * $level ** $xp['exponent'];
    }

    /** The highest level that this much experience reaches. A new driver is on the first. */
    public function levelAt(float $xp): int
    {
        $level = 1;

        while ($level < self::MAX_LEVEL && $this->xpToReach($level + 1) <= $xp) {
            $level++;
        }

        return $level;
    }

    /** The experience of an hour of driving, which the activity of the player multiplies. */
    public function xpPerHour(PlayerType $player): float
    {
        return $this->data['model']['xp']['per_hour'] * $player->activity;
    }

    /** The experience that a lap gives before the activity of the player: the one a rule of the game writes down. */
    public function xpPerLap(int $rung): float
    {
        return $this->data['model']['xp']['per_hour'] / $this->lapsPerHour($rung);
    }

    /** What a player earns in a day at this rung, after the service, the repairs and the tires. */
    public function dailyIncome(PlayerType $player, int $rung): float
    {
        $hours = $player->hoursPerDay($this->offlineWindowHours($rung));

        return $this->netPerHour($rung) * $hours * $player->activity * (1 - $this->data['model']['upkeep']) * $player->fleetAt($rung);
    }

    /** @return array<string, PlayerType> */
    public function players(): array
    {
        return $this->players;
    }

    /**
     * The days on which a player has the car of rung 1, 2 and so on, by the design.
     *
     * @return list<int>
     */
    public function targets(string $player): array
    {
        return array_values($this->data['targets'][$player]);
    }

    /** What a new car of the tier costs, in dollars, or null for a tier the anchors do not know. */
    public function newCarPrice(int $tier): ?float
    {
        $prices = $this->data['anchors']['new_car_by_tier'];

        return isset($prices[$tier - 1]) ? (float) $prices[$tier - 1] : null;
    }

    /** What a part of this tier and slot should cost by the anchors, in dollars, or null when no anchor says. */
    public function partPrice(int $tier, string $slot): ?float
    {
        $car = $this->newCarPrice($tier);
        $share = $this->data['anchors']['parts']['slot_share'][$slot] ?? null;

        return $car === null || $share === null ? null : $car * $this->data['anchors']['parts']['set_share'] * $share;
    }

    public function gateTolerance(): float
    {
        return (float) $this->data['gate']['tolerance'];
    }

    public function gateSlackDays(): float
    {
        return (float) $this->data['gate']['slack_days'];
    }

    public function gateMaxDays(): int
    {
        return (int) $this->data['gate']['max_days'];
    }

    public function consistencyTolerance(): float
    {
        return (float) $this->data['consistency']['tolerance'];
    }
}
