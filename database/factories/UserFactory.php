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

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function seller(): static
    {
        return $this->state(fn () => ['role' => UserRole::Seller]);
    }

    /** Admins and other staff come with two-factor authentication enabled, as required in production. */
    public function admin(): static
    {
        return $this->staff(UserRole::Admin);
    }

    public function staff(UserRole $role): static
    {
        return $this->state(fn () => ['role' => $role])->withTwoFactor();
    }

    public const TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function withTwoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => self::TWO_FACTOR_SECRET,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ]);
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
