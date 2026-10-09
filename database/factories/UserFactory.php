<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
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
     * The hashed password shared by factory users: "correct-horse-battery-1".
     */
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password_hash' => static::$password ??= Hash::make('correct-horse-battery-1'),
            'role' => UserRole::Buyer,
            'status' => UserStatus::Active,
            'balance_minor' => 0,
            'currency' => 'USD',
            'remember_token' => Str::random(10),
        ];
    }

    public function seller(): static
    {
        return $this->state(fn () => ['role' => UserRole::Seller]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }

    public function withBalance(int $minor, string $currency = 'USD'): static
    {
        return $this->state(fn () => ['balance_minor' => $minor, 'currency' => $currency]);
    }
}
