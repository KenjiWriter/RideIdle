<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\RunFreezer;
use App\Models\RaceRun;
use App\Support\I18nText;

/**
 * The tracks of a city as a driver chooses between them: what each is (its kind, its surface, the level it needs) and, for the car that the driver plays, what a
 * lap on it takes, pays and burns, how many laps a tank lasts, what an hour of driving earns after the fuel, and where the car would stand among the bots. A sprint
 * burns little fuel an hour and pays little a lap, a standard track is in between, an endurance track burns a tank in minutes and pays a lot a lap, so which one
 * is better depends on the car, on the tank and on how long the driver is away.
 */
final class TrackChoices
{
    public function __construct(private readonly Rules $rules, private readonly RunInputsFactory $inputs) {}

    /**
     * The tracks of a city, in the order of the rules (the first is the one a car drives on when it arrives).
     *
     * @param  RaceRun|null  $run  the run of the car that is played: without it there is nothing to say about the car
     * @param  int  $playerLevel  the level of the driver
     * @return list<array<string, mixed>>
     */
    public function forCity(string $region, ?RaceRun $run, int $playerLevel): array
    {
        $choices = [];

        foreach ($this->rules->tracksOf($region) as $slug) {
            $track = $this->rules->track($slug);
            $required = $this->rules->trackMinPlayerLevel($slug);

            $choices[] = [
                'slug' => $slug,
                'name' => I18nText::from($track['name'])->for(),
                'kind' => $this->rules->trackKind($slug),
                'surface' => $track['surface'],
                'lit' => $track['lit'],
                'level_required' => $required,
                'open' => $playerLevel >= $required,
                'current' => $run !== null && $run->track_slug === $slug,
                'length_m' => $track['length_m'],
                'base_lap_ms' => $track['base_lap_ms'],
                'demand' => $track['demand'],
                'car' => $run === null ? null : $this->forCar($run, $slug),
            ];
        }

        return $choices;
    }

    /**
     * What a lap of the track is for the car of a run: the numbers of the dry lap, the fuel and the tank.
     *
     * @return array{lap_ms: int, cash_cents: int, xp: int, fuel_ml: int, tank_ml: int, fits_tank: bool, laps_per_tank: int, tank_ms: int, gross_cents_per_hour: int, fuel_cents_per_hour: int, net_cents_per_hour: int, xp_per_hour: int, fit_percent: int, position: int|null, field_size: int|null}
     */
    public function forCar(RaceRun $run, string $trackSlug): array
    {
        $config = (new RunFreezer)->freeze($this->inputs->forRun($run, $trackSlug), 'track-choices', 0)->config;
        $track = $this->rules->track($trackSlug);
        $lapMs = (int) $config['lap_ms']['dry'];
        $cash = (int) $config['lap_cash_cents']['dry'];
        $fuelMl = (int) $config['fuel_per_lap_ml'];
        $tankMl = $this->inputs->tankMl($run->car_slug, $run->car?->mountedParts() ?? []);
        $laps = intdiv($tankMl, max(1, $fuelMl));
        $perHour = static fn (int|float $amount): int => (int) round($amount * 3_600_000 / max(1, $lapMs));
        $fuelCents = $fuelMl * $this->rules->fuelPriceCentsPerLiter() / 1000;

        return [
            'lap_ms' => $lapMs,
            'cash_cents' => $cash,
            'xp' => (int) $config['lap_xp']['dry'],
            'fuel_ml' => $fuelMl,
            'tank_ml' => $tankMl,
            // A car that cannot hold the fuel of one lap cannot drive the track at all.
            'fits_tank' => $fuelMl <= $tankMl,
            'laps_per_tank' => $laps,
            'tank_ms' => $laps * $lapMs,
            'gross_cents_per_hour' => $perHour($cash),
            'fuel_cents_per_hour' => $perHour($fuelCents),
            'net_cents_per_hour' => $perHour($cash) - $perHour($fuelCents),
            'xp_per_hour' => $perHour((int) $config['lap_xp']['dry']),
            'fit_percent' => (int) round(100 * $track['base_lap_ms'] / $lapMs),
            'position' => $config['field']['position'] ?? null,
            'field_size' => $config['field']['size'] ?? null,
        ];
    }
}
