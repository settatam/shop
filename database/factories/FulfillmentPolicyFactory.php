<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FulfillmentPolicy>
 */
class FulfillmentPolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->randomElement(['Standard Shipping', 'Express Shipping', 'Free Shipping', 'Economy Shipping']),
            'description' => fake()->optional()->sentence(),
            'handling_time_value' => fake()->randomElement([1, 2, 3, 5]),
            'handling_time_unit' => 'DAY',
            'shipping_type' => fake()->randomElement(['flat_rate', 'calculated', 'freight']),
            'domestic_shipping_cost' => fake()->randomFloat(2, 0, 25),
            'international_shipping_cost' => fake()->optional()->randomFloat(2, 10, 50),
            'free_shipping' => false,
            'shipping_carrier' => fake()->optional()->randomElement(['USPS', 'UPS', 'FedEx', 'DHL']),
            'shipping_service' => fake()->optional()->randomElement(['Priority', 'Ground', 'Express', 'Standard']),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function freeShipping(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Free Shipping',
            'free_shipping' => true,
            'domestic_shipping_cost' => 0,
        ]);
    }
}
