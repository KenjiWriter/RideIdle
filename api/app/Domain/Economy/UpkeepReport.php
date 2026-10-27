<?php

declare(strict_types=1);

namespace App\Domain\Economy;

use Closure;

/**
 * What the game charges for keeping a car, set against what the model takes off the income for it (`model.upkeep`, a tenth: service, repairs and tires).
 * The game charges two things: a service every `every_km` of the odometer, which costs a share of what the car earns in that stretch, and the repair of the parts
 * that wear, which costs a share of the price of a part for the whole life of it that was used up. Both are per kilometre, so what an hour of driving
 * costs follows from how far the car drives in an hour, which is the laps of an hour times the length of a lap.
 *
 * It knows no pack and no rules: the collector (the command) hands over, for every level of the ladder of the game, the income of the best pair of car and
 * track, how long a lap of it takes and is and what it pays, and which parts of the shop the car would have on it, with what they wear a kilometre.
 * A report only: the numbers do not touch a gate.
 *
 * Wear is counted in billionths of the life of a part (a billion is worn out), as in `App\Domain\Run\WearPlan`.
 */
final class UpkeepReport
{
    private const HOUR_MS = 3_600_000;

    private const WORN_OUT_PPB = 1_000_000_000;

    /**
     * @param  int  $serviceEveryKm  how many kilometres the car drives between two services
     * @param  Closure(int, int): int  $serviceCostCents  what a service costs, in cents, for a car whose lap pays this many cents on a track whose lap is this many metres long
     * @param  int  $repairPpmOfPrice  what the repair of a part costs for the whole of its life, as a share of its price in ppm (half of it: 500 000)
     */
    public function __construct(
        private readonly int $serviceEveryKm,
        private readonly Closure $serviceCostCents,
        private readonly int $repairPpmOfPrice,
    ) {}

    /**
     * What an hour of driving costs a car at each level, and what share of its income that is.
     *
     * @param  list<array{level: int, car: string, track: string, net: float, lap_ms: int, lap_length_m: int, lap_cash_cents: int, parts: list<array{slot: string, model: string, price_cents: int, ppb_per_km: int}>}>  $levels  the best pair of car and track of each level, as the ladder of the game has it
     * @return list<array{level: int, car: string, track: string, net: float, km_per_hour: float, service: float, parts: float, upkeep: float, share: float, set_price: float, set: list<array{slot: string, model: string, price: float, per_hour: float}>}>
     */
    public function rows(array $levels): array
    {
        return array_map(function (array $level): array {
            $kmPerHour = (float) (self::HOUR_MS / $level['lap_ms'] * $level['lap_length_m'] / 1000);
            $service = ($this->serviceCostCents)($level['lap_cash_cents'], $level['lap_length_m']) / 100 / $this->serviceEveryKm * $kmPerHour;

            $set = array_map(fn (array $part): array => [
                'slot' => $part['slot'],
                'model' => $part['model'],
                'price' => $part['price_cents'] / 100,
                'per_hour' => $this->partPerHour($part['price_cents'], $part['ppb_per_km'], $kmPerHour),
            ], $level['parts']);

            $parts = (float) array_sum(array_column($set, 'per_hour'));
            $upkeep = $service + $parts;

            return [
                'level' => $level['level'],
                'car' => $level['car'],
                'track' => $level['track'],
                'net' => $level['net'],
                'km_per_hour' => $kmPerHour,
                'service' => $service,
                'parts' => $parts,
                'upkeep' => $upkeep,
                'share' => $level['net'] > 0 ? $upkeep / $level['net'] : 0.0,
                'set_price' => (float) array_sum(array_column($set, 'price')),
                'set' => $set,
            ];
        }, $levels);
    }

    /**
     * What the repairs of one part cost in an hour: the share of its price that its whole life costs, for the part of its life that a kilometre uses
     * (`ppb_per_km` over a billion), for the kilometres of an hour. Dollars.
     */
    public function partPerHour(int $priceCents, int $ppbPerKm, float $kmPerHour): float
    {
        return $priceCents / 100 * $this->repairPpmOfPrice / 1_000_000 * $ppbPerKm / self::WORN_OUT_PPB * $kmPerHour;
    }

    /**
     * The lowest and the highest share of the income that the upkeep takes over the levels, the middle one, and the levels where the game charges less than
     * the model takes off and the ones where it charges more (a level that charges exactly as much is in neither).
     *
     * @param  list<array{level: int, share: float}>  $rows
     * @return array{min: float, max: float, median: float, model: float, below: list<int>, above: list<int>}
     */
    public function summary(array $rows, float $modelShare): array
    {
        $shares = array_column($rows, 'share');
        sort($shares);
        $count = count($shares);
        $median = $count === 0 ? 0.0 : ($count % 2 === 1 ? $shares[intdiv($count, 2)] : ($shares[$count / 2 - 1] + $shares[$count / 2]) / 2);

        return [
            'min' => $shares === [] ? 0.0 : $shares[0],
            'max' => $shares === [] ? 0.0 : $shares[$count - 1],
            'median' => $median,
            'model' => $modelShare,
            'below' => array_values(array_map(static fn (array $row): int => $row['level'], array_filter($rows, static fn (array $row): bool => $row['share'] < $modelShare))),
            'above' => array_values(array_map(static fn (array $row): int => $row['level'], array_filter($rows, static fn (array $row): bool => $row['share'] > $modelShare))),
        ];
    }
}
