<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => fake()->unique()->regexify('[A-Z]{3}-[0-9]{5}'),
            'price' => fake()->randomFloat(2, 10, 500),
            'cost' => fake()->randomFloat(2, 5, 200),
            'quantity' => fake()->numberBetween(0, 100),
            'barcode' => fake()->optional()->ean13(),
            'status' => 'active',
            'is_active' => true,
            'weight' => fake()->randomFloat(2, 0.1, 10),
            'weight_unit' => 'kg',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'status' => 'inactive',
        ]);
    }

    public function withOptions(string $name, string $value): static
    {
        return $this->state(fn (array $attributes) => [
            'option1_name' => $name,
            'option1_value' => $value,
        ]);
    }
}
