<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TutorialProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TutorialProgress>
 */
class TutorialProgressFactory extends Factory
{
    /**
     * A step that the driver has just reached.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->guest(),
            'step_key' => 'first-lap',
            'state' => TutorialProgress::STATE_ACTIVE,
            'started_at' => now(),
        ];
    }
}
