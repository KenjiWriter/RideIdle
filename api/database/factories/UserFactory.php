<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state: a claimed account with an e-mail.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::limit(fake()->unique()->userName(), User::NAME_MAX_LENGTH, '');

        return [
            'name' => $name,
            'name_key' => User::nameKey($name),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'claimed_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    /**
     * A guest account: no e-mail, no password, not claimed.
     */
    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => null,
            'email_verified_at' => null,
            'password' => null,
            'remember_token' => null,
            'claimed_at' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
