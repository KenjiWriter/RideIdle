<?php

declare(strict_types=1);

namespace App\Domain\Run;

use App\Domain\Settlement\Ppm;

/**
 * Freezes the configuration of a run: from the car, the track and the weather it computes, once, the lap
 * time, the reward and the fuel use as integers. Settlement later reads only these integers.
 *
 * This is the one place where floating point numbers are allowed. The results are rounded and stored,
 * so a different last bit of a power function on another machine cannot change a finished settlement.
 *
 * It runs at the start of a run and whenever the car, a part, the track or the service state changes,
 * always after the run was settled up to that moment.
 */
final class RunFreezer
{
    /** How many laps ahead the planned breakdown is searched. Without a hit no breakdown is planned. */
    private const BREAKDOWN_SEARCH_LAPS = 10_000;

    private const STAT_FLOOR = 0.2;

    private const STAT_CAP = 1.6;

    private const LAP_TIME_EXPONENT = 1.15;

    public function freeze(RunInputs $inputs, string $runSeed, int $lapsDone): FrozenRun
    {
        $lapMs = [];
        $lapCash = [];
        $lapXp = [];

        foreach (array_keys($inputs->weatherChancesPpm) as $weather) {
            $effect = $inputs->effectFor($weather);

            $lapMs[$weather] = $this->lapMs($inputs, $effect);
            $lapCash[$weather] = Ppm::mul($inputs->track->rewardCentsPerLap, $effect->rewardPpm);
            $lapXp[$weather] = Ppm::mul($inputs->track->xpPerLap, $effect->xpPpm);
        }

        return new FrozenRun([
            'lap_ms' => $lapMs,
            'lap_cash_cents' => $lapCash,
            'lap_xp' => $lapXp,
            'fuel_per_lap_ml' => max(1, (int) round($inputs->track->fuelPerLapMl * $inputs->car->fuelUseMultiplier)),
            'weather' => ['seed' => $inputs->climateSeed, 'chances_ppm' => $inputs->weatherChancesPpm],
            'night_lap_ppm' => $inputs->nightLapPpm,
        ], $this->breakdownLap($runSeed, $lapsDone, $inputs->breakChancePerLapPpm));
    }

    /** Lap time in whole milliseconds: base time divided by perf to the power 1.15. */
    private function lapMs(RunInputs $inputs, WeatherEffect $effect): int
    {
        $perf = 0.0;

        foreach ($inputs->track->weights as $stat => $weight) {
            $value = $inputs->car->get($stat) * $effect->multiplierFor($stat);
            $ratio = $value / $inputs->track->references[$stat];

            $perf += $weight * max(self::STAT_FLOOR, min(self::STAT_CAP, $ratio));
        }

        return max(1, (int) round($inputs->track->baseLapMs / ($perf ** self::LAP_TIME_EXPONENT)));
    }

    /**
     * The first lap after the current one at which the car breaks down: every lap has the same chance,
     * decided by the seed. The search uses the absolute lap number, so a new seed after a repair
     * gives an independent plan.
     */
    private function breakdownLap(string $runSeed, int $lapsDone, int $chancePpm): ?int
    {
        if ($chancePpm === 0) {
            return null;
        }

        for ($lap = $lapsDone + 1; $lap <= $lapsDone + self::BREAKDOWN_SEARCH_LAPS; $lap++) {
            if (Rng::roll($runSeed, 'break', $lap) < $chancePpm) {
                return $lap;
            }
        }

        return null;
    }
}
