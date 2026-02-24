<?php

namespace Database\Factories;

use App\Models\PlatformListing;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlatformListingVariant>
 */
class PlatformListingVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform_listing_id' => PlatformListing::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'status' => 'active',
        ];
    }

    public function withPricing(): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => $this->faker->randomFloat(2, 10, 1000),
            'compare_at_price' => $this->faker->randomFloat(2, 50, 2000),
            'quantity' => $this->faker->numberBetween(0, 100),
        ]);
    }

    public function withExternalIds(): static
    {
        return $this->state(fn (array $attributes) => [
            'external_variant_id' => (string) $this->faker->unique()->numberBetween(10000000000, 99999999999),
            'external_inventory_item_id' => (string) $this->faker->unique()->numberBetween(10000000000, 99999999999),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
