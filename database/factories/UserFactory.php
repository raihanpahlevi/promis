<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

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
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'npp' => fake()->unique()->numerify('#######'),
            'nama_lengkap' => fake()->name(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'sales',
            'unit_id' => null,
            'force_password_change' => false,
            'is_active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'admin']);
    }

    public function adminFinal(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'admin_final']);
    }
}
