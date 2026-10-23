<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Car;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Car>
 */
class CarFactory extends Factory
{
    /**
     * A new Rdzak GT-4 at the level a new car has.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->guest(),
            'vehicle_slug' => 'rdzak-gt-4',
            'level' => 3,
        ];
    }
}
