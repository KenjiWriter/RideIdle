<?php

declare(strict_types=1);

namespace App\Content\Studio;

/**
 * What a number of the rules is counted in, told by the end of its name (the rules say `_ppm`, `_ms`, `_cents` and `_ml` for the things a person thinks of as a
 * percent, seconds, dollars and liters). The document keeps the whole number; the studio shows and takes the unit a person thinks in, and turns it back.
 */
enum Unit: string
{
    case Plain = 'plain';

    /** Parts per million: a share or a multiple (1 300 000 is 130%, a multiple of 1.3). */
    case Percent = 'ppm';

    case Seconds = 'ms';

    case Dollars = 'cents';

    case Liters = 'ml';

    public static function of(string $key): self
    {
        // The word can be anywhere in the name: `base_time_ms`, `time_cut_ppm_per_level`, `fuel_cents_per_hour`.
        return match (true) {
            preg_match('/(^|_)ppm(_|$)/', $key) === 1 => self::Percent,
            preg_match('/(^|_)ms(_|$)/', $key) === 1 => self::Seconds,
            preg_match('/(^|_)cents(_|$)/', $key) === 1 => self::Dollars,
            preg_match('/(^|_)ml(_|$)/', $key) === 1 => self::Liters,
            default => self::Plain,
        };
    }

    /** How many of the whole numbers of the document are one of the unit. */
    public function divisor(): int
    {
        return match ($this) {
            self::Plain => 1,
            self::Percent => 10_000,
            self::Seconds, self::Liters => 1_000,
            self::Dollars => 100,
        };
    }

    /** What is written after the number. */
    public function suffix(): string
    {
        return match ($this) {
            self::Plain => '',
            self::Percent => '%',
            self::Seconds => 's',
            self::Dollars => '$',
            self::Liters => 'l',
        };
    }

    /** The number as a person reads it (130.0 for a ppm of 1 300 000), without the zeros that mean nothing. */
    public function display(int|float $raw): int|float
    {
        if ($this === self::Plain) {
            return $raw;
        }

        $shown = round($raw / $this->divisor(), 4);

        return $shown == (int) $shown ? (int) $shown : $shown;
    }

    /** The whole number of the document for what a person wrote. */
    public function raw(int|float $display, bool $integer = true): int|float
    {
        if ($this === self::Plain) {
            return $integer ? (int) round($display) : $display;
        }

        $raw = round($display * $this->divisor());

        return $integer ? (int) $raw : $raw;
    }

    /** The smallest step a person can ask for, in the unit (a ppm is a millionth, so a hundredth of a percent is the step that is worth having). */
    public function step(bool $integer = true): int|float
    {
        return match ($this) {
            self::Plain => $integer ? 1 : 0.01,
            self::Percent => 0.01,
            self::Seconds => 0.1,
            self::Dollars => 0.01,
            self::Liters => 0.001,
        };
    }
}
