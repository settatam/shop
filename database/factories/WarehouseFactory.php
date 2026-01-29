<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Warehouse>
 */
class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->company().' Warehouse',
            'code' => 'WH-'.fake()->unique()->numerify('###'),
            'description' => fake()->sentence(),
            'address_line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country' => 'US',
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'contact_name' => fake()->name(),
            'is_default' => false,
            'is_active' => true,
            'accepts_transfers' => true,
            'fulfills_orders' => true,
            'priority' => 0,
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

    public function transferOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'fulfills_orders' => false,
            'accepts_transfers' => true,
        ]);
    }

    public function fulfillmentOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'fulfills_orders' => true,
            'accepts_transfers' => false,
        ]);
    }

    public function withTaxRate(float $rate): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_rate' => $rate,
        ]);
    }
}
