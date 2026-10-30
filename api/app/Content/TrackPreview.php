<?php

declare(strict_types=1);

namespace App\Content;

use InvalidArgumentException;
use OverflowException;

/**
 * What a track gives the car it is made for, as numbers that a person is shown while the track is being written (the form of the world): the lap, the speed it comes to, the
 * fuel, what a lap pays and gives, and how many laps there are in an hour. It is `TrackNumbers` read the other way, with a track that may not be finished: nothing when the
 * track does not say enough for the game to make its numbers.
 */
final class TrackPreview
{
    /**
     * @param  array<string, mixed>  $track  the track as the document would have it (metres, km/h, cents)
     * @param  array<string, int>  $loads  the load of the engine by kind, `TrackNumbers::fuelLoads()`
     * @return array{length_m: int, lap_ms: int, speed_kmh: float, fuel_ml: int, cash_cents: int, xp: int, laps_per_hour: float}|null
     */
    public static function of(array $track, array $loads): ?array
    {
        try {
            $made = TrackNumbers::derive($track, $loads);
        } catch (InvalidArgumentException|OverflowException) {
            return null;
        }

        $length = $made['length_m'] ?? null;
        $lap = $made['base_lap_ms'] ?? null;

        if (! is_int($length) || ! is_int($lap) || $lap < 1 || ! isset($made['fuel_per_lap_ml'], $made['reward_cents_per_lap'], $made['xp_per_lap'])) {
            return null;
        }

        return [
            'length_m' => $length,
            'lap_ms' => $lap,
            'speed_kmh' => $length * 3.6 / ($lap / 1000),
            'fuel_ml' => (int) $made['fuel_per_lap_ml'],
            'cash_cents' => (int) $made['reward_cents_per_lap'],
            'xp' => (int) $made['xp_per_lap'],
            'laps_per_hour' => 3_600_000 / $lap,
        ];
    }
}
