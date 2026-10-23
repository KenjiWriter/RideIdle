<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Car;
use App\Models\Part;
use App\Models\WorkshopJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkshopJob>
 */
class WorkshopJobFactory extends Factory
{
    /**
     * A queued job of putting a part on a car that lasts a minute from ms 0. Give it the car and the part
     * of the same driver, or it makes a car and a part of a new one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn (array $attributes): int => Car::query()->findOrFail($attributes['car_id'])->user_id,
            'car_id' => Car::factory(),
            'part_id' => fn (array $attributes): int => Part::factory()->create([
                'user_id' => Car::query()->findOrFail($attributes['car_id'])->user_id,
            ])->id,
            'kind' => WorkshopJob::KIND_MOUNT,
            'status' => WorkshopJob::STATUS_QUEUED,
            'start_ms' => 0,
            'finish_ms' => 60_000,
        ];
    }
}
