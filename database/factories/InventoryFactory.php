<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Inventory>
 */
class InventoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'quantity' => fake()->numberBetween(0, 100),
            'reserved_quantity' => 0,
            'incoming_quantity' => 0,
            'reorder_point' => fake()->optional()->numberBetween(5, 20),
            'reorder_quantity' => fake()->optional()->numberBetween(20, 50),
            'safety_stock' => fake()->numberBetween(0, 10),
            'bin_location' => fake()->optional()->regexify('[A-Z][1-9]-[A-Z][1-9]-[0-9]{2}'),
            'unit_cost' => fake()->randomFloat(4, 1, 100),
        ];
    }

    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => 5,
            'safety_stock' => 10,
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => 0,
            'reserved_quantity' => 0,
        ]);
    }

    public function withReservations(int $reserved): static
    {
        return $this->state(fn (array $attributes) => [
            'reserved_quantity' => $reserved,
        ]);
    }

    public function needsReorder(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => 8,
            'reorder_point' => 10,
            'reorder_quantity' => 50,
        ]);
    }
}
