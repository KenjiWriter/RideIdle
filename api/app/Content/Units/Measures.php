<?php

declare(strict_types=1);

namespace App\Content\Units;

use App\Content\ContentException;
use App\Content\Rules;
use App\Support\Locale;
use Illuminate\Support\Facades\Lang;

/**
 * What a game counts distance and speed in: the unit of a lap of a track, the unit of the roads, the odometer and the service, the unit of speed, and how much distance
 * the fuel use is shown per. The rules of a game say it in `units`, and a game that does not says the units of the car game (metres, kilometres, km/h and the litres of 100 km).
 *
 * The numbers of the rules, of the database and of the API do not change with it: they are metres, kilometres and km/h in every game, and the units are what a person writes and
 * is shown. Reading is lenient: a part that is not what it should be is the one of the car game.
 */
final readonly class Measures
{
    /** The presets of a game that is started: the units of the car game, of the ships, and of the miles of the roads. */
    public const PRESETS = ['metric', 'nautical', 'imperial'];

    public function __construct(public DistanceUnit $lap, public DistanceUnit $travel, public SpeedUnit $speed, public int $fuelEconomyPer = 100) {}

    /** The units of the car game. */
    public static function metric(): self
    {
        return self::preset('metric');
    }

    /** The units of the game that is in force (its rules say them), and the car game's for a game that has no rules yet. */
    public static function ofTheGame(): self
    {
        try {
            return app(Rules::class)->measures();
        } catch (ContentException) {
            return self::metric();
        }
    }

    /** The units of a preset: `metric`, `nautical` or `imperial`. */
    public static function preset(string $preset): self
    {
        return match ($preset) {
            'nautical' => new self(
                self::distance('nautical_mile', 'nm', 1852, 2),
                self::distance('nautical_mile', 'nm', 1852, 0),
                self::speed('knot', 'kn', 1.852, 0),
                1,
            ),
            'imperial' => new self(
                self::distance('mile', 'mi', 1609.344, 2),
                self::distance('mile', 'mi', 1609.344, 0),
                self::speed('mph', 'mph', 1.609344, 0),
                100,
            ),
            default => new self(
                self::distance('metre', 'm', 1, 0),
                self::distance('kilometre', 'km', 1000, 0),
                self::speed('kmh', 'km/h', 1, 0),
                100,
            ),
        };
    }

    /**
     * The units that the rules say (the document of the rules, as it is), where it says them, and the units of the car game where it does not.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function fromRules(array $rules): self
    {
        $units = is_array($rules['units'] ?? null) ? $rules['units'] : [];
        $default = self::metric();

        return new self(
            self::readDistance($units['lap'] ?? null) ?? $default->lap,
            self::readDistance($units['travel'] ?? null) ?? $default->travel,
            self::readSpeed($units['speed'] ?? null) ?? $default->speed,
            in_array($units['fuel_economy']['per'] ?? null, [1, 10, 100], true) ? $units['fuel_economy']['per'] : $default->fuelEconomyPer,
        );
    }

    /**
     * The units as the rules write them (`units`): what a game that starts from a preset has in its document.
     *
     * @return array<string, mixed>
     */
    public function toRules(): array
    {
        return [
            'lap' => ['name' => $this->lap->name, 'short' => $this->lap->short, 'metres' => $this->lap->metres, 'decimals' => $this->lap->decimals],
            'travel' => ['name' => $this->travel->name, 'short' => $this->travel->short, 'metres' => $this->travel->metres, 'decimals' => $this->travel->decimals],
            'speed' => ['name' => $this->speed->name, 'short' => $this->speed->short, 'kmh' => $this->speed->kmh, 'decimals' => $this->speed->decimals],
            'fuel_economy' => ['per' => $this->fuelEconomyPer],
        ];
    }

    /**
     * The units as the app reads them (`GET /api/v1/meta/game`): the same as the rules write them, with the economy of the fuel as a number.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lap' => $this->toRules()['lap'],
            'travel' => $this->toRules()['travel'],
            'speed' => $this->toRules()['speed'],
            'fuel_economy_per' => $this->fuelEconomyPer,
        ];
    }

    /** @return array<string, string> the name of a unit in every language of the product, from the files of the language of the app */
    private static function names(string $key): array
    {
        $names = [];

        foreach (Locale::supported() as $locale) {
            $names[$locale] = (string) Lang::get("units.{$key}", [], str_replace('-', '_', $locale));
        }

        return $names;
    }

    private static function distance(string $name, string $short, int|float $metres, int $decimals): DistanceUnit
    {
        return new DistanceUnit(self::names($name), array_fill_keys(Locale::supported(), $short), $metres, $decimals);
    }

    private static function speed(string $name, string $short, int|float $kmh, int $decimals): SpeedUnit
    {
        return new SpeedUnit(self::names($name), array_fill_keys(Locale::supported(), $short), $kmh, $decimals);
    }

    private static function readDistance(mixed $unit): ?DistanceUnit
    {
        if (! is_array($unit) || ! is_numeric($unit['metres'] ?? null) || $unit['metres'] <= 0) {
            return null;
        }

        return new DistanceUnit(self::texts($unit['name'] ?? null), self::texts($unit['short'] ?? null), $unit['metres'], self::decimals($unit['decimals'] ?? null));
    }

    private static function readSpeed(mixed $unit): ?SpeedUnit
    {
        if (! is_array($unit) || ! is_numeric($unit['kmh'] ?? null) || $unit['kmh'] <= 0) {
            return null;
        }

        return new SpeedUnit(self::texts($unit['name'] ?? null), self::texts($unit['short'] ?? null), $unit['kmh'], self::decimals($unit['decimals'] ?? null));
    }

    /** @return array<string, string> the texts by language that a document has, only the ones that are texts */
    private static function texts(mixed $texts): array
    {
        return is_array($texts) ? array_filter($texts, static fn (mixed $text): bool => is_string($text) && trim($text) !== '') : [];
    }

    private static function decimals(mixed $decimals): int
    {
        return is_int($decimals) && $decimals >= 0 && $decimals <= 4 ? $decimals : 0;
    }
}
