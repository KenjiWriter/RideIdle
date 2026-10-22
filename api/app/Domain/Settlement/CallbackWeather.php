<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use Closure;

/** Weather from a function of the segment number, handy for tests and simulations. */
final readonly class CallbackWeather implements WeatherSchedule
{
    /** @param Closure(int): string $callback */
    public function __construct(private Closure $callback)
    {
    }

    public function at(int $segment): string
    {
        return ($this->callback)($segment);
    }
}
