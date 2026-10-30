<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Run\TrackModel;

/**
 * The numbers of a track that the game reads (the lap of the reference car, its fuel, the pay and the experience of a lap) made from what the document says. A track
 * says its length and its speed, and what an hour of it pays and gives, and the kind of the track says the load of the engine (`track_model.fuel_ml_per_s`): the rest
 * follows (`TrackModel`). A document that says a number itself (`base_lap_ms`, `fuel_per_lap_ml`, `reward_cents_per_lap`, `xp_per_lap`) is believed over the model: the
 * games and the packs that were made before the model have those, and run as they did.
 *
 * Where the floating point numbers of the document (the speed with its decimals, the experience of an hour) are turned into integers, and nowhere else.
 */
final class TrackNumbers
{
    /** The load of the engine of the kinds of track, in ml of fuel for every second of a lap, when the rules do not say. */
    public const DEFAULT_FUEL_ML_PER_S = ['sprint' => 12, 'standard' => 20, 'endurance' => 32];

    /** The kind of a track that does not say. */
    public const DEFAULT_KIND = 'standard';

    /**
     * The load of the engine by kind of track.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, int>
     */
    public static function fuelLoads(array $rules): array
    {
        return [...self::DEFAULT_FUEL_ML_PER_S, ...array_map('intval', (array) ($rules['track_model']['fuel_ml_per_s'] ?? []))];
    }

    /** The speed of a document in ten-thousandths of a km/h. */
    public static function speedScaled(int|float $kmh): int
    {
        return (int) round($kmh * TrackModel::SPEED_SCALE);
    }

    /** The experience of an hour of a document in hundredths of a point. */
    public static function centiXp(int|float $xpPerHour): int
    {
        return (int) round($xpPerHour * 100);
    }

    /** A speed in ten-thousandths of a km/h as the document writes it. */
    public static function speedKmh(int $speedScaled): int|float
    {
        $kmh = round($speedScaled / TrackModel::SPEED_SCALE, 4);

        return $kmh == (int) $kmh ? (int) $kmh : $kmh;
    }

    /** The experience of an hour in hundredths of a point as the document writes it. */
    public static function xpPerHour(int $centiXp): int|float
    {
        $xp = round($centiXp / 100, 2);

        return $xp == (int) $xp ? (int) $xp : $xp;
    }

    /**
     * The track with the numbers that the game reads: the lap, the fuel, the pay and the experience of a lap, each the one the document says or the one the model makes of
     * the length, the speed and the hour; and, for whoever shows them, the speed, the pay and the experience of an hour that the numbers come to. A track that has neither
     * what the model needs nor the number itself is left as it is (the schema is what refuses it).
     *
     * @param  array<string, mixed>  $track
     * @param  array<string, int>  $loads  the load of the engine by kind, `fuelLoads()`
     * @return array<string, mixed>
     */
    public static function derive(array $track, array $loads): array
    {
        $length = $track['length_m'] ?? null;

        if (! is_int($length) || $length < 1 || $length > TrackModel::LONGEST_LAP_M) {
            return $track;
        }

        $lap = $track['base_lap_ms'] ?? (isset($track['speed_kmh']) && is_numeric($track['speed_kmh']) && $track['speed_kmh'] > 0 ? TrackModel::lapMs($length, self::speedScaled($track['speed_kmh'])) : null);

        if (! is_int($lap) || $lap < 1) {
            return $track;
        }

        $kind = (string) ($track['kind'] ?? self::DEFAULT_KIND);
        $load = $loads[$kind] ?? $loads[self::DEFAULT_KIND];

        $track['base_lap_ms'] = $lap;
        $track['fuel_per_lap_ml'] ??= TrackModel::fuelMl($load, $lap);

        if (! isset($track['reward_cents_per_lap']) && isset($track['pay_cents_per_hour'])) {
            $track['reward_cents_per_lap'] = TrackModel::cashCents((int) $track['pay_cents_per_hour'], $lap);
        }

        if (! isset($track['xp_per_lap']) && isset($track['xp_per_hour'])) {
            $track['xp_per_lap'] = TrackModel::xp(self::centiXp($track['xp_per_hour']), $lap);
        }

        $track['fuel_ml_per_s'] = $load;
        $track['speed_kmh'] ??= self::speedKmh(TrackModel::speedScaledOf($length, $lap));

        if (isset($track['reward_cents_per_lap'])) {
            $track['pay_cents_per_hour'] ??= TrackModel::centsPerHourOf((int) $track['reward_cents_per_lap'], $lap);
        }

        if (isset($track['xp_per_lap'])) {
            $track['xp_per_hour'] ??= self::xpPerHour(TrackModel::centiXpPerHourOf((int) $track['xp_per_lap'], $lap));
        }

        return $track;
    }
}
