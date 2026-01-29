<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        $price = fake()->randomFloat(2, 10, 200);
        $cost = $price * fake()->randomFloat(2, 0.3, 0.7);

        return [
            'order_id' => Order::factory(),
            'product_id' => null,
            'product_variant_id' => null,
            'sku' => fake()->unique()->regexify('[A-Z]{3}-[0-9]{5}'),
            'title' => fake()->words(3, true),
            'quantity' => fake()->numberBetween(1, 5),
            'price' => $price,
            'cost' => $cost,
            'discount' => fake()->optional(0.2)->randomFloat(2, 1, 20) ?? 0,
            'tax' => 0,
        ];
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'sku' => $variant->sku,
            'title' => $variant->title ?? $variant->product?->title ?? 'Product',
            'price' => $variant->price,
            'cost' => $variant->cost,
        ]);
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
            'title' => $product->title,
        ]);
    }

    public function withDiscount(float $discount): static
    {
        return $this->state(fn (array $attributes) => [
            'discount' => $discount,
        ]);
    }

    public function withTax(float $tax): static
    {
        return $this->state(fn (array $attributes) => [
            'tax' => $tax,
        ]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
        ]);
    }
}
