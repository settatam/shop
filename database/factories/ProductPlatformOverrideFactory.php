<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StoreMarketplace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductPlatformOverride>
 */
class ProductPlatformOverrideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'store_marketplace_id' => StoreMarketplace::factory(),
            'title' => fake()->optional(0.5)->sentence(4),
            'description' => fake()->optional(0.3)->paragraph(),
            'price' => fake()->optional(0.4)->randomFloat(2, 10, 1000),
            'compare_at_price' => null,
            'quantity' => fake()->optional(0.3)->numberBetween(0, 100),
            'attributes' => [],
            'category_id' => null,
            'is_active' => true,
        ];
    }

    /**
     * Create with custom pricing.
     */
    public function withPricing(float $price, ?float $compareAtPrice = null): static
    {
        return $this->state(fn () => [
            'price' => $price,
            'compare_at_price' => $compareAtPrice,
        ]);
    }

    /**
     * Create with custom title.
     */
    public function withTitle(string $title): static
    {
        return $this->state(fn () => ['title' => $title]);
    }

    /**
     * Mark as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
