<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Run\RunFreezer;

/**
 * What the game really pays for an hour of driving, from the pack and the rules, by the level of the driver (the other side of the game ladder:
 * `App\Domain\Economy\GameLadder` sets it against the anchors).
 *
 * The net profit of an hour is the laps in an hour of the lap that the run is frozen into (dry, the base lap), times what a lap pays less the
 * fuel it burns at the price of the rules. A car has its stock parts (what parts and their rarity add is for the stages that come after), and
 * what a level has to choose from is:
 *
 * - the combustion cars of the pack that are for sale at the dealer or on the exchange and ask this level or less of a driver, and
 * - the wrecks of the yard (`garage.wreck_price_cents`), which a driver can buy from the first level, whatever level their model asks for,
 * - on the tracks of the rules that are open at this level.
 *
 * The best pair of car and track is the income of the level, so the income never falls as the level rises.
 */
final class GameIncome
{
    private const HOUR_MS = 3_600_000;

    /** The levels that are always asked about: the first one, where a driver has a wreck and the first track. */
    private const FIRST_LEVEL = 1;

    /** @var array<string, float> the net profit per hour by "car|track" */
    private array $net = [];

    public function __construct(
        private readonly Catalog $catalog,
        private readonly Rules $rules,
        private readonly RunInputsFactory $inputs,
    ) {}

    /**
     * The best income for every level that matters: the first level, every level at which a car for sale or a track opens, and the ones asked for.
     *
     * @param  list<int>  $levels  more levels to answer for, for example the levels of the rungs of the ladder
     * @return array<int, array{net: float, car: string, track: string}> from the lowest level to the highest
     */
    public function byLevel(array $levels = []): array
    {
        $asked = array_unique([self::FIRST_LEVEL, ...$levels, ...$this->carLevels(), ...$this->trackLevels()]);
        sort($asked);

        $incomes = [];

        foreach ($asked as $level) {
            $best = null;

            foreach ($this->carsAt($level) as $car) {
                foreach ($this->tracksAt($level) as $track) {
                    $net = $this->netPerHour($car, $track);

                    if ($best === null || $net > $best['net']) {
                        $best = ['net' => $net, 'car' => $car, 'track' => $track];
                    }
                }
            }

            if ($best !== null) {
                $incomes[$level] = $best;
            }
        }

        return $incomes;
    }

    /** The net profit of an hour of driving this car on this track with the stock parts, in dry weather, in dollars. */
    public function netPerHour(string $car, string $track): float
    {
        return $this->net["{$car}|{$track}"] ??= $this->computeNetPerHour($car, $track);
    }

    /**
     * The cars a driver of this level can have: the combustion ones for sale that ask this level or less, and the wrecks of the yard.
     *
     * @return list<string> slugs, in the order of the pack
     */
    public function carsAt(int $level): array
    {
        $cars = [];

        foreach ($this->catalog->vehicles() as $slug => $vehicle) {
            if (($vehicle['powertrain'] ?? null) !== 'ice') {
                continue;
            }

            $forSale = array_intersect($vehicle['sources'], ['dealer', 'exchange']) !== [] && $vehicle['min_player_level'] <= $level;

            if ($forSale || $this->rules->wreckPriceCents($slug) !== null) {
                $cars[] = $slug;
            }
        }

        return $cars;
    }

    /**
     * The tracks that are open at this level.
     *
     * @return list<string> slugs, in the order of the rules
     */
    public function tracksAt(int $level): array
    {
        return array_values(array_filter(
            array_keys($this->rules->tracks()),
            fn (string $track): bool => $this->rules->trackMinPlayerLevel($track) <= $level,
        ));
    }

    /** @return list<int> the levels at which a car for sale opens */
    private function carLevels(): array
    {
        $levels = [];

        foreach ($this->catalog->vehicles() as $vehicle) {
            if (($vehicle['powertrain'] ?? null) === 'ice' && array_intersect($vehicle['sources'], ['dealer', 'exchange']) !== []) {
                $levels[] = $vehicle['min_player_level'];
            }
        }

        return $levels;
    }

    /** @return list<int> the levels at which a track opens */
    private function trackLevels(): array
    {
        return array_map(fn (string $track): int => $this->rules->trackMinPlayerLevel($track), array_keys($this->rules->tracks()));
    }

    private function computeNetPerHour(string $car, string $track): float
    {
        $config = (new RunFreezer)->freeze($this->inputs->make($car, $track), 'game-ladder', 0)->config;
        $lapsPerHour = self::HOUR_MS / $config['lap_ms']['dry'];
        $fuelCost = $config['fuel_per_lap_ml'] / 1000 * $this->rules->fuelPriceCentsPerLiter() / 100;

        return $lapsPerHour * ($config['lap_cash_cents']['dry'] / 100 - $fuelCost);
    }
}
