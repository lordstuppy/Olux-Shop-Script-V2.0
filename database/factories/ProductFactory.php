<?php

namespace Database\Factories;

use App\Enums\DeliveryType;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $title = ucfirst(fake()->words(3, true));

        return [
            'seller_id' => User::factory()->seller(),
            'category_id' => null,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'description' => fake()->paragraph(),
            'price_minor' => 2500,
            'currency' => 'USD',
            'stock' => null,
            'delivery_type' => DeliveryType::Instant,
            'status' => ProductStatus::Active,
        ];
    }

    public function stock(?int $stock): static
    {
        return $this->state(fn () => ['stock' => $stock]);
    }

    public function price(int $minor, string $currency = 'USD'): static
    {
        return $this->state(fn () => ['price_minor' => $minor, 'currency' => $currency]);
    }

    public function manual(): static
    {
        return $this->state(fn () => ['delivery_type' => DeliveryType::Manual]);
    }

    public function status(ProductStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
