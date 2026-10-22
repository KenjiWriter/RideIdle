<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/** The same weather in every segment. */
final readonly class ConstantWeather implements WeatherSchedule
{
    public function __construct(private string $weather = 'dry')
    {
    }

    public function at(int $segment): string
    {
        return $this->weather;
    }
}
