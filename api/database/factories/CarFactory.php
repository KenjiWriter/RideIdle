<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Content\Rules;
use App\Models\Car;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Car>
 */
class CarFactory extends Factory
{
    /**
     * A new Rdzak GT-4 at the level a new car has, with the experience at which that level begins.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rules = app(Rules::class);

        return [
            'user_id' => User::factory()->guest(),
            'vehicle_slug' => 'rdzak-gt-4',
            'level' => $rules->carStartLevel(),
            'xp' => $rules->carXpToReach($rules->carStartLevel()),
        ];
    }

    /** A car of this level, with the experience at which it begins. */
    public function atLevel(int $level): static
    {
        return $this->state(fn (): array => ['level' => $level, 'xp' => app(Rules::class)->carXpToReach($level)]);
    }
}
