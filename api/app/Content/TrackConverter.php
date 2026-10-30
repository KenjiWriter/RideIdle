<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Run\TrackModel;

/**
 * A track that says its four numbers by hand (the lap, the fuel, what a lap pays and what it gives) as a track that says what the model needs (its speed, what an hour
 * pays and gives), with the same numbers made of them to the millisecond, the millilitre, the cent and the point of XP.
 *
 * What cannot be said exactly is not converted: the number stays as it was, said by hand, and the report says so. The speed is written with as few decimals as it takes
 * (at most four), the experience of an hour with at most two; a fuel that is not what the engine burns in the time of the lap at the load of the kind stays by hand.
 */
final class TrackConverter
{
    /**
     * @param  array<string, mixed>  $track
     * @param  array<string, int>  $loads  the load of the engine by kind, `TrackNumbers::fuelLoads()`
     * @return array{track: array<string, mixed>, report: list<array{field: string, before: int|float|null, after: int|float|null, converted: bool, note: string}>}
     */
    public static function convert(array $track, array $loads): array
    {
        $report = [];
        $length = $track['length_m'] ?? null;
        $lap = $track['base_lap_ms'] ?? null;

        if (! is_int($length) || $length < 1 || $length > TrackModel::LONGEST_LAP_M) {
            return ['track' => $track, 'report' => []];
        }

        // The speed: the fewest decimals that make the lap exactly. Without a lap by hand there is nothing to convert, and the speed that the track says is the one that counts.
        $speed = null;

        if (is_int($lap) && $lap > 0) {
            [$speed, $exact] = self::speedOf($length, $lap);
            $report[] = ['field' => 'base_lap_ms', 'before' => $lap, 'after' => $speed === null ? null : TrackNumbers::speedKmh($speed), 'converted' => $exact, 'note' => $exact ? 'speed_kmh' : 'no speed of at most four decimals makes this lap: the lap stays by hand'];
        }

        // What the lap is that the numbers are made of: the one that stays by hand, or the one that the speed makes.
        $effectiveLap = is_int($lap) ? $lap : (isset($track['speed_kmh']) && is_numeric($track['speed_kmh']) ? TrackModel::lapMs($length, TrackNumbers::speedScaled($track['speed_kmh'])) : null);

        if ($effectiveLap === null) {
            return ['track' => $track, 'report' => $report];
        }

        $kind = (string) ($track['kind'] ?? TrackNumbers::DEFAULT_KIND);
        $load = $loads[$kind] ?? $loads[TrackNumbers::DEFAULT_KIND];
        $converted = [];

        if (isset($track['fuel_per_lap_ml'])) {
            $made = TrackModel::fuelMl($load, $effectiveLap);
            $same = $track['fuel_per_lap_ml'] === $made;
            $report[] = ['field' => 'fuel_per_lap_ml', 'before' => $track['fuel_per_lap_ml'], 'after' => $made, 'converted' => $same, 'note' => $same ? "burns {$load} ml for every second of the lap" : "the engine would burn {$made} ml in this lap at the load of the kind: the fuel stays by hand"];
        }

        if (isset($track['reward_cents_per_lap'])) {
            $pay = self::hourOf($track['reward_cents_per_lap'], $effectiveLap, static fn (int $perHour): int => TrackModel::cashCents($perHour, $effectiveLap), 1, 1);
            $report[] = ['field' => 'reward_cents_per_lap', 'before' => $track['reward_cents_per_lap'], 'after' => $pay, 'converted' => $pay !== null, 'note' => $pay !== null ? 'pay_cents_per_hour' : 'no pay of an hour makes this lap pay: it stays by hand'];
            $converted['pay_cents_per_hour'] = $pay;
        }

        if (isset($track['xp_per_lap'])) {
            $centi = self::hourOf($track['xp_per_lap'], $effectiveLap, static fn (int $perHour): int => TrackModel::xp($perHour, $effectiveLap), 100, 0);
            $report[] = ['field' => 'xp_per_lap', 'before' => $track['xp_per_lap'], 'after' => $centi === null ? null : TrackNumbers::xpPerHour($centi), 'converted' => $centi !== null, 'note' => $centi !== null ? 'xp_per_hour' : 'no experience of an hour makes this lap give this: it stays by hand'];
            $converted['xp_per_hour'] = $centi === null ? null : TrackNumbers::xpPerHour($centi);
        }

        $result = [];

        foreach ($track as $key => $value) {
            match ($key) {
                'base_lap_ms' => self::put($result, $key, $value, $speed !== null && self::exactLap($length, $speed, $lap) ? ['speed_kmh' => TrackNumbers::speedKmh($speed)] : null),
                'fuel_per_lap_ml' => self::put($result, $key, $value, $value === TrackModel::fuelMl($load, $effectiveLap) ? [] : null),
                'reward_cents_per_lap' => self::put($result, $key, $value, ($converted['pay_cents_per_hour'] ?? null) !== null ? ['pay_cents_per_hour' => $converted['pay_cents_per_hour']] : null),
                'xp_per_lap' => self::put($result, $key, $value, ($converted['xp_per_hour'] ?? null) !== null ? ['xp_per_hour' => $converted['xp_per_hour']] : null),
                default => $result[$key] = $value,
            };
        }

        return ['track' => $result, 'report' => $report];
    }

    /**
     * Puts what replaces a number of the document where the number was: what is given, or the number itself when nothing is (it cannot be made exactly).
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, int|float>|null  $replacement  null keeps the number
     */
    private static function put(array &$result, string $key, mixed $value, ?array $replacement): void
    {
        if ($replacement === null) {
            $result[$key] = $value;

            return;
        }

        foreach ($replacement as $newKey => $newValue) {
            $result[$newKey] = $newValue;
        }
    }

    /** Whether this speed makes exactly this lap. */
    private static function exactLap(int $length, int $speedScaled, int $lap): bool
    {
        return TrackModel::lapMs($length, $speedScaled) === $lap;
    }

    /**
     * The speed, in ten-thousandths of a km/h, that makes the lap with the fewest decimals, and whether it makes it exactly: when no speed of at most four decimals does, the
     * nearest one of four.
     *
     * @return array{0: int|null, 1: bool}
     */
    private static function speedOf(int $length, int $lap): array
    {
        $nearest = TrackModel::speedScaledOf($length, $lap);

        for ($decimals = 0; $decimals <= 4; $decimals++) {
            $step = 10 ** (4 - $decimals);
            $candidate = intdiv($nearest + intdiv($step, 2), $step) * $step;

            if ($candidate > 0 && TrackModel::lapMs($length, $candidate) === $lap) {
                return [$candidate, true];
            }
        }

        // The neighbours of the nearest: the rounding of the inverse is not always the number that rounds back.
        foreach ([-2, -1, 1, 2] as $offset) {
            if ($nearest + $offset > 0 && TrackModel::lapMs($length, $nearest + $offset) === $lap) {
                return [$nearest + $offset, true];
            }
        }

        return [$nearest, false];
    }

    /**
     * What an hour gives for a lap, the nearest that gives it exactly: the number is looked for next to the one that the lap divides into, and null when none of them does.
     *
     * @param  callable(int): int  $lapOf  what a lap gives for an hour of this much
     * @param  int  $scale  1 for cents, 100 for hundredths of a point
     * @param  int  $minimum  the least that the document allows (a pay is at least one cent, an experience may be nothing)
     */
    private static function hourOf(int $lapGives, int $lap, callable $lapOf, int $scale, int $minimum): ?int
    {
        $centre = $scale === 1 ? TrackModel::centsPerHourOf($lapGives, $lap) : TrackModel::centiXpPerHourOf($lapGives, $lap);

        for ($offset = 0; $offset <= 50; $offset++) {
            foreach ($offset === 0 ? [0] : [-$offset, $offset] as $signed) {
                $candidate = $centre + $signed;

                if ($candidate >= $minimum && $lapOf($candidate) === $lapGives) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
