<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RaceRun;
use App\Models\Travel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Travel>
 */
class TravelFactory extends Factory
{
    /**
     * A journey from the first city to the second that has just begun and lasts 30 minutes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->guest(),
            'race_run_id' => fn (array $attributes): int => RaceRun::factory()->create(['user_id' => $attributes['user_id']])->id,
            'from_region' => 'zlomowo',
            'to_region' => 'kruszyn',
            'distance_km' => 38,
            'fuel_ml' => 2_280,
            'start_ms' => 0,
            'arrive_ms' => 1_800_000,
            'status' => Travel::STATUS_TRAVELLING,
        ];
    }
}
