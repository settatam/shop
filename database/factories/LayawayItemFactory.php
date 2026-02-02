<?php

namespace Database\Factories;

use App\Models\Layaway;
use App\Models\LayawayItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LayawayItem>
 */
class LayawayItemFactory extends Factory
{
    protected $model = LayawayItem::class;

    public function definition(): array
    {
        $price = fake()->randomFloat(2, 50, 500);
        $quantity = fake()->numberBetween(1, 3);

        return [
            'layaway_id' => Layaway::factory(),
            'product_id' => null,
            'product_variant_id' => null,
            'sku' => fake()->optional()->bothify('LAY-????-####'),
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'quantity' => $quantity,
            'price' => $price,
            'line_total' => $price * $quantity,
            'is_reserved' => true,
        ];
    }

    public function reserved(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_reserved' => true,
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_reserved' => false,
        ]);
    }

    public function withProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'title' => $product->title,
            'price' => $product->price,
        ]);
    }

    public function withQuantity(int $quantity): static
    {
        return $this->state(function (array $attributes) use ($quantity) {
            return [
                'quantity' => $quantity,
                'line_total' => $attributes['price'] * $quantity,
            ];
        });
    }

    public function withPricing(float $price): static
    {
        return $this->state(function (array $attributes) use ($price) {
            return [
                'price' => $price,
                'line_total' => $price * $attributes['quantity'],
            ];
        });
    }
}
