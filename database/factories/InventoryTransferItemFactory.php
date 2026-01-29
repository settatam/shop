<?php

namespace Database\Factories;

use App\Models\InventoryTransfer;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InventoryTransferItem>
 */
class InventoryTransferItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inventory_transfer_id' => InventoryTransfer::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity_requested' => fake()->numberBetween(1, 50),
            'quantity_shipped' => 0,
            'quantity_received' => 0,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function shipped(): static
    {
        return $this->state(function (array $attributes) {
            $requested = $attributes['quantity_requested'] ?? fake()->numberBetween(1, 50);

            return [
                'quantity_requested' => $requested,
                'quantity_shipped' => $requested,
            ];
        });
    }

    public function received(): static
    {
        return $this->state(function (array $attributes) {
            $requested = $attributes['quantity_requested'] ?? fake()->numberBetween(1, 50);

            return [
                'quantity_requested' => $requested,
                'quantity_shipped' => $requested,
                'quantity_received' => $requested,
            ];
        });
    }

    public function partiallyReceived(): static
    {
        return $this->state(function (array $attributes) {
            $requested = $attributes['quantity_requested'] ?? fake()->numberBetween(10, 50);
            $received = (int) ($requested * 0.7);

            return [
                'quantity_requested' => $requested,
                'quantity_shipped' => $requested,
                'quantity_received' => $received,
            ];
        });
    }
}
