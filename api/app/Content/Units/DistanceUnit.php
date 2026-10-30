<?php

declare(strict_types=1);

namespace App\Content\Units;

use App\Support\I18nText;

/**
 * A unit of distance that a game counts in: its name and its short form in the languages, how many metres it is, and how many decimals it is shown with. The numbers
 * of the rules and of the API stay in metres and kilometres; this is what a person is shown, and writes, instead.
 */
final readonly class DistanceUnit
{
    /**
     * @param  array<string, string>  $name  by language
     * @param  array<string, string>  $short  by language
     * @param  int|float  $metres  how many metres one of the unit is
     */
    public function __construct(public array $name, public array $short, public int|float $metres, public int $decimals) {}

    public function nameFor(?string $locale = null): string
    {
        return I18nText::from($this->name)->for($locale);
    }

    public function shortFor(?string $locale = null): string
    {
        return I18nText::from($this->short)->for($locale);
    }

    /** A distance in metres as a number of the unit, as it is shown (rounded to the decimals of the unit). */
    public function shown(int|float $metres): int|float
    {
        return $this->clean(round($metres / $this->metres, $this->decimals));
    }

    /**
     * A distance in metres as a number of the unit for a box that a person writes in: with enough decimals to come back to the same metre, whatever the decimals of the unit
     * (a lap that is 2 600 m is 1.403888 nautical miles, and writing it back gives 2 600 m).
     */
    public function inBox(int|float $metres): int|float
    {
        return $this->clean(round($metres / $this->metres, max($this->decimals, 6)));
    }

    /** What a person wrote, in the unit, as the whole metres of the document. */
    public function toMetres(int|float $value): int
    {
        return (int) round($value * $this->metres);
    }

    private function clean(float $value): int|float
    {
        return $value == (int) $value ? (int) $value : $value;
    }
}
