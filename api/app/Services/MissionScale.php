<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Models\RaceRun;

/**
 * What the missions are measured against: a tank of the best car of the driver. A driver who looks in once a day finds a car that has driven one tank, so the
 * laps of a tank, what they pay after the fuel and the XP they give are what a day of play is worth to them, and the goals and the rewards of the missions are
 * parts of it. They follow the economy of the game that way: a better car, a longer tank or a higher payout changes the missions without a number being edited.
 *
 * The best car is the one whose tank earns most after the fuel, among the cars that have a run, on the track each is on, so that the goals cannot be made
 * smaller by playing a poor car on the day the set is drawn.
 */
final class MissionScale
{
    public function __construct(private readonly TrackChoices $tracks, private readonly Rules $rules) {}

    /**
     * @return array{laps: int, lap_cash_cents: int, gross_cents: int, net_cents: int, xp: int, fuel_cents: int}|null null when the driver has no car that can drive its track
     */
    public function tankful(int $userId): ?array
    {
        $best = null;

        foreach (RaceRun::query()->where('user_id', $userId)->whereNotNull('car_id')->with('car')->orderBy('id')->get() as $run) {
            $tank = $this->tankOf($run);

            if ($tank !== null && ($best === null || $tank['net_cents'] > $best['net_cents'])) {
                $best = $tank;
            }
        }

        return $best;
    }

    /**
     * A tank of the car of a run on its track: its laps, what they pay, what the fuel of them costs, the XP, and what it costs to fill the tank. Null for a car
     * that cannot hold the fuel of one lap.
     *
     * @return array{laps: int, lap_cash_cents: int, gross_cents: int, net_cents: int, xp: int, fuel_cents: int}|null
     */
    public function tankOf(RaceRun $run): ?array
    {
        $car = $this->tracks->forCar($run, $run->track_slug);
        $laps = $car['laps_per_tank'];

        if (! $car['fits_tank'] || $laps < 1) {
            return null;
        }

        $gross = $laps * $car['cash_cents'];
        $used = (int) round($laps * $car['fuel_ml'] * $this->rules->fuelPriceCentsPerLiter() / 1000);

        return [
            'laps' => $laps,
            'lap_cash_cents' => $car['cash_cents'],
            'gross_cents' => $gross,
            'net_cents' => $gross - $used,
            'xp' => $laps * $car['xp'],
            // Filling the whole tank.
            'fuel_cents' => (int) ceil($car['tank_ml'] * $this->rules->fuelPriceCentsPerLiter() / 1000),
        ];
    }
}
