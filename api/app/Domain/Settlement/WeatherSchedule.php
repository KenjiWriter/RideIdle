<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Tells what the weather is in a 22.5 minute segment (segment = floor(epoch ms / 1 350 000)).
 * The real schedule comes from the seeded climate of the region; settlement only asks.
 */
interface WeatherSchedule
{
    /** @return string a key of the lap tables in RunConfig, for example dry, rain or night */
    public function at(int $segment): string;
}
