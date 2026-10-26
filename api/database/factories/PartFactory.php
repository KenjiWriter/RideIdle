<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Car;
use App\Models\Part;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Part>
 */
class PartFactory extends Factory
{
    /**
     * A part in the inventory: street tires of tier 2.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->guest(),
            'part_slug' => 'street-tires-t2',
            'slot' => 'tires',
            'rarity' => 1,
            'car_id' => null,
            'mounted_slot' => null,
        ];
    }

    /** The part sits on the car, in its own slot. Set the slot (with `state`) before this, as the slot is read here. */
    public function mountedOn(Car $car): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $car->user_id,
            'car_id' => $car->id,
            'mounted_slot' => $attributes['slot'],
        ]);
    }
}
