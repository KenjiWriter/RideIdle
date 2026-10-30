<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Run\TrackModel;

/**
 * The speed that a track of such corners, climbs and traffic may be given, for the button of the form of a track that suggests one: the free speed of the surface less what
 * the corners, the climbs and the traffic take off. It reads what the rules say in `track_model.advisor` and nothing else, and the game reads none of it: a track says its
 * speed, and this is only a help to find one for a new track (the tracks that exist are not changed by it).
 */
final class TrackAdvisor
{
    /** What the rules say when they say nothing: 180 km/h on asphalt and 130 on gravel, and 10, 5 and 5 km/h off for a corner a kilometre, 10 m of climb a kilometre and a step of traffic. */
    public const DEFAULTS = [
        'free_speed_kmh' => ['asphalt' => 180, 'gravel' => 130],
        'corner_loss_kmh' => 10,
        'climb_loss_kmh' => 5,
        'traffic_loss_kmh' => 5,
    ];

    /** How many steps of traffic a track has at the most: an empty track is 0, a tight and crowded one 3. */
    public const MOST_TRAFFIC = 3;

    /**
     * What the rules say of the help, with what they do not say filled in.
     *
     * @param  array<string, mixed>  $rules
     * @return array{free_speed_kmh: array{asphalt: int|float, gravel: int|float}, corner_loss_kmh: int|float, climb_loss_kmh: int|float, traffic_loss_kmh: int|float}
     */
    public static function constants(array $rules): array
    {
        $said = is_array($rules['track_model']['advisor'] ?? null) ? $rules['track_model']['advisor'] : [];

        return [
            'free_speed_kmh' => [...self::DEFAULTS['free_speed_kmh'], ...array_filter((array) ($said['free_speed_kmh'] ?? []), 'is_numeric')],
            'corner_loss_kmh' => is_numeric($said['corner_loss_kmh'] ?? null) ? $said['corner_loss_kmh'] : self::DEFAULTS['corner_loss_kmh'],
            'climb_loss_kmh' => is_numeric($said['climb_loss_kmh'] ?? null) ? $said['climb_loss_kmh'] : self::DEFAULTS['climb_loss_kmh'],
            'traffic_loss_kmh' => is_numeric($said['traffic_loss_kmh'] ?? null) ? $said['traffic_loss_kmh'] : self::DEFAULTS['traffic_loss_kmh'],
        ];
    }

    /**
     * The speed of the reference car on such a track, in km/h with one decimal, never under 20 or over 300 (what the schema allows a track).
     *
     * @param  string  $surface  `asphalt` or `gravel`
     * @param  int|float  $cornersPerKm  the sharp corners in a kilometre
     * @param  int|float  $climbMetresPerKm  how many metres the track climbs in a kilometre
     * @param  int  $traffic  0 for an empty track to 3 for a tight and crowded one
     * @param  array{free_speed_kmh: array{asphalt: int|float, gravel: int|float}, corner_loss_kmh: int|float, climb_loss_kmh: int|float, traffic_loss_kmh: int|float}  $constants  `constants()`
     */
    public static function speedKmh(string $surface, int|float $cornersPerKm, int|float $climbMetresPerKm, int $traffic, array $constants): int|float
    {
        $free = $constants['free_speed_kmh'][$surface] ?? $constants['free_speed_kmh']['asphalt'];
        $speed = $free
            - max(0, $cornersPerKm) * $constants['corner_loss_kmh']
            - max(0, $climbMetresPerKm) / 10 * $constants['climb_loss_kmh']
            - max(0, min(self::MOST_TRAFFIC, $traffic)) * $constants['traffic_loss_kmh'];

        $speed = round(max(TrackModel::MIN_SPEED_KMH, min(TrackModel::MAX_SPEED_KMH, $speed)), 1);

        return $speed == (int) $speed ? (int) $speed : $speed;
    }
}
