<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Purchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    /**
     * The small pack of the shipped catalog, paid by a test provider.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->guest(),
            'product' => 'coins-small',
            'coins' => 50,
            'amount_minor' => 499,
            'currency' => 'PLN',
            'provider' => 'test',
            'provider_ref' => 'pay_'.fake()->unique()->bothify('????########'),
            'status' => Purchase::STATUS_PAID,
        ];
    }
}
