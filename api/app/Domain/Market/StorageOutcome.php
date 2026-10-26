<?php

declare(strict_types=1);

namespace App\Domain\Market;

/** What was in a storage unit: a car, with the age, the mileage and the condition it has, and what it is worth. */
final readonly class StorageOutcome
{
    public const SCRAP = 'scrap';

    public const JUNK = 'junk';

    public const GOOD = 'good';

    public const GEM = 'gem';

    /**
     * @param  string  $class  scrap, junk, good or gem
     * @param  int  $conditionPpm  from 0 to 1 000 000
     * @param  int  $defects  how many of its parts are worn out, from the condition
     * @param  int  $valueCents  what the car is worth by the formula of the exchange
     */
    public function __construct(
        public string $class,
        public string $vehicle,
        public int $ageYears,
        public int $mileageKm,
        public int $conditionPpm,
        public int $defects,
        public int $valueCents,
    ) {}
}
