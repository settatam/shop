<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\ProductReturn;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ReturnItem>
 */
class ReturnItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 3);
        $unitPrice = fake()->randomFloat(2, 10, 200);

        return [
            'return_id' => ProductReturn::factory(),
            'order_item_id' => null,
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
            'condition' => fake()->randomElement(['new', 'like_new', 'used', 'damaged']),
            'reason' => fake()->optional()->randomElement(['defective', 'wrong_item', 'not_as_described', 'changed_mind']),
            'notes' => fake()->optional()->sentence(),
            'restock' => true,
            'restocked' => false,
            'restocked_at' => null,
            'exchange_variant_id' => null,
            'exchange_quantity' => null,
        ];
    }

    public function withOrderItem(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_item_id' => OrderItem::factory(),
        ]);
    }

    public function restocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'restocked' => true,
            'restocked_at' => now(),
        ]);
    }

    public function noRestock(): static
    {
        return $this->state(fn (array $attributes) => [
            'restock' => false,
        ]);
    }

    public function damaged(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition' => 'damaged',
            'restock' => false,
        ]);
    }

    public function exchange(): static
    {
        return $this->state(fn (array $attributes) => [
            'exchange_variant_id' => ProductVariant::factory(),
            'exchange_quantity' => $attributes['quantity'] ?? 1,
        ]);
    }

    public function likeNew(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition' => 'like_new',
            'restock' => true,
        ]);
    }
}
