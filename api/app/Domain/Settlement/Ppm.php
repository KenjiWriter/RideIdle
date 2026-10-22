<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use OverflowException;

/**
 * Multipliers and chances are integers in parts per million (1 000 000 = 1.0),
 * so the settlement never touches floating point numbers.
 */
final class Ppm
{
    public const ONE = 1_000_000;

    /**
     * Multiplies an integer amount by a ppm factor and rounds half away from
     * zero: mul(1550, 930000) is 1442 and mul(-1550, 930000) is -1442.
     */
    public static function mul(int $amount, int $ppm): int
    {
        if ($amount === 0 || $ppm === 0) {
            return 0;
        }

        $magnitude = abs($amount);

        if ($magnitude > intdiv(PHP_INT_MAX - intdiv(self::ONE, 2), abs($ppm))) {
            throw new OverflowException("Amount {$amount} is too large for the multiplier {$ppm}.");
        }

        $rounded = intdiv($magnitude * abs($ppm) + intdiv(self::ONE, 2), self::ONE);

        return ($amount < 0) !== ($ppm < 0) ? -$rounded : $rounded;
    }
}
