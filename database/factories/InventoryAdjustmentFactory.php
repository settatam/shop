<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InventoryAdjustment>
 */
class InventoryAdjustmentFactory extends Factory
{
    public function definition(): array
    {
        $quantityBefore = fake()->numberBetween(10, 100);
        $quantityChange = fake()->numberBetween(-10, 10);

        return [
            'store_id' => Store::factory(),
            'inventory_id' => Inventory::factory(),
            'user_id' => User::factory(),
            'reference' => 'ADJ-'.fake()->unique()->numerify('######'),
            'type' => fake()->randomElement(InventoryAdjustment::TYPES),
            'quantity_before' => $quantityBefore,
            'quantity_change' => $quantityChange,
            'quantity_after' => $quantityBefore + $quantityChange,
            'unit_cost' => fake()->randomFloat(4, 1, 50),
            'total_cost_impact' => $quantityChange * fake()->randomFloat(4, 1, 50),
            'reason' => fake()->sentence(),
            'notes' => fake()->optional()->paragraph(),
        ];
    }

    public function increase(int $amount = 10): static
    {
        return $this->state(function (array $attributes) use ($amount) {
            $before = $attributes['quantity_before'] ?? fake()->numberBetween(10, 100);

            return [
                'type' => InventoryAdjustment::TYPE_RECEIVED,
                'quantity_before' => $before,
                'quantity_change' => $amount,
                'quantity_after' => $before + $amount,
            ];
        });
    }

    public function decrease(int $amount = 10): static
    {
        return $this->state(function (array $attributes) use ($amount) {
            $before = $attributes['quantity_before'] ?? fake()->numberBetween(20, 100);

            return [
                'type' => InventoryAdjustment::TYPE_DAMAGED,
                'quantity_before' => $before,
                'quantity_change' => -$amount,
                'quantity_after' => $before - $amount,
            ];
        });
    }

    public function damaged(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InventoryAdjustment::TYPE_DAMAGED,
        ]);
    }

    public function correction(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InventoryAdjustment::TYPE_CORRECTION,
        ]);
    }

    public function cycleCount(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InventoryAdjustment::TYPE_CYCLE_COUNT,
        ]);
    }
}
