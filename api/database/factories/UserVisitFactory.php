<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserVisit>
 */
class UserVisitFactory extends Factory
{
    /**
     * A first visit of a guest, now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->guest(),
            'at' => now(),
            'gap_minutes' => null,
        ];
    }
}
