<?php

declare(strict_types=1);

namespace App\Content\Units;

use App\Support\I18nText;

/** A unit of speed that a game counts in: how many km/h it is, its name and short form in the languages, and the decimals it is shown with. */
final readonly class SpeedUnit
{
    /**
     * @param  array<string, string>  $name  by language
     * @param  array<string, string>  $short  by language
     * @param  int|float  $kmh  how many km/h one of the unit is
     */
    public function __construct(public array $name, public array $short, public int|float $kmh, public int $decimals) {}

    public function nameFor(?string $locale = null): string
    {
        return I18nText::from($this->name)->for($locale);
    }

    public function shortFor(?string $locale = null): string
    {
        return I18nText::from($this->short)->for($locale);
    }

    /** A speed in km/h as a number of the unit, as it is shown. */
    public function shown(int|float $kmh): int|float
    {
        return $this->clean(round($kmh / $this->kmh, $this->decimals));
    }

    /** A speed in km/h as a number of the unit for a box that a person writes in: enough decimals to come back to the same speed of four decimals. */
    public function inBox(int|float $kmh): int|float
    {
        return $this->clean(round($kmh / $this->kmh, max($this->decimals, 6)));
    }

    /** What a person wrote, in the unit, as the km/h of the document: at most four decimals, as the schema asks. */
    public function toKmh(int|float $value): int|float
    {
        return $this->clean(round($value * $this->kmh, 4));
    }

    private function clean(float $value): int|float
    {
        return $value == (int) $value ? (int) $value : $value;
    }
}
