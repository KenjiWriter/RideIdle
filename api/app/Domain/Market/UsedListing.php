<?php

declare(strict_types=1);

namespace App\Domain\Market;

/**
 * A car on the used-car exchange. The condition is the truth, and only an inspection tells it; before that the driver has the mileage,
 * the age, the price and a word (good, fair, poor) that only roughly agrees with the truth.
 */
final readonly class UsedListing
{
    public const GOOD = 'good';

    public const FAIR = 'fair';

    public const POOR = 'poor';

    /**
     * @param  string  $key  what the driver asks for: the city, the refresh the car was listed in, its place and a fingerprint of the car
     * @param  int  $conditionPpm  the average condition of its parts, from 0 to 1 000 000
     * @param  int  $defects  how many of its parts are worn out (what the condition is made of)
     * @param  string  $word  what the listing says of the condition: good, fair or poor
     */
    public function __construct(
        public string $key,
        public string $region,
        public int $window,
        public int $index,
        public string $vehicle,
        public int $ageYears,
        public int $mileageKm,
        public int $conditionPpm,
        public int $defects,
        public string $word,
        public int $priceCents,
    ) {}
}
