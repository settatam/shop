<?php

namespace Database\Factories;

use App\Models\InventoryTransfer;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InventoryTransfer>
 */
class InventoryTransferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'from_warehouse_id' => Warehouse::factory(),
            'to_warehouse_id' => Warehouse::factory(),
            'created_by' => User::factory(),
            'reference' => 'TRF-'.fake()->unique()->numerify('######'),
            'status' => InventoryTransfer::STATUS_DRAFT,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InventoryTransfer::STATUS_DRAFT,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InventoryTransfer::STATUS_PENDING,
        ]);
    }

    public function inTransit(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InventoryTransfer::STATUS_IN_TRANSIT,
            'shipped_at' => now(),
        ]);
    }

    public function received(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InventoryTransfer::STATUS_RECEIVED,
            'shipped_at' => now()->subDays(2),
            'received_at' => now(),
            'received_by' => User::factory(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InventoryTransfer::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function withExpectedDate(): static
    {
        return $this->state(fn (array $attributes) => [
            'expected_at' => now()->addDays(fake()->numberBetween(1, 7)),
        ]);
    }
}
