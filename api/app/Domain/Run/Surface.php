<?php

declare(strict_types=1);

namespace App\Domain\Run;

use InvalidArgumentException;

/**
 * What a car drives on: the track when it is dry, and what the weather makes of it. The five surfaces are the columns of
 * the tire matrix of the rules (Systemy II): rain wets asphalt and turns gravel into mud, and snow covers either.
 */
final class Surface
{
    public const ASPHALT_DRY = 'asphalt_dry';

    public const ASPHALT_WET = 'asphalt_wet';

    public const GRAVEL_DRY = 'gravel_dry';

    public const MUD = 'mud';

    public const SNOW = 'snow';

    /** The surfaces, in the order the matrix lists them. */
    public const ALL = [self::ASPHALT_DRY, self::ASPHALT_WET, self::GRAVEL_DRY, self::MUD, self::SNOW];

    /** The surface of a track made of `$trackSurface` (asphalt or gravel) when the weather leaves its ground dry, wet or snowy. */
    public static function of(string $trackSurface, string $ground): string
    {
        return match ([$trackSurface, $ground]) {
            ['asphalt', 'dry'] => self::ASPHALT_DRY,
            ['asphalt', 'wet'] => self::ASPHALT_WET,
            ['gravel', 'dry'] => self::GRAVEL_DRY,
            ['gravel', 'wet'] => self::MUD,
            ['asphalt', 'snow'], ['gravel', 'snow'] => self::SNOW,
            default => throw new InvalidArgumentException("There is no surface of {$trackSurface} with a {$ground} ground."),
        };
    }
}
