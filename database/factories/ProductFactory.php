<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->words(3, true);

        return [
            'store_id' => Store::factory(),
            'title' => $title,
            'handle' => Str::slug($title).'-'.fake()->unique()->randomNumber(5),
            'description' => fake()->paragraph(),
            'status' => Product::STATUS_ACTIVE,
            'is_published' => true,
            'track_quantity' => true,
            'quantity' => fake()->numberBetween(0, 100),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Product::STATUS_ACTIVE,
            'is_published' => true,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Product::STATUS_DRAFT,
            'is_draft' => '1',
            'is_published' => false,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Product::STATUS_ARCHIVE,
            'is_published' => false,
        ]);
    }

    public function sold(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Product::STATUS_SOLD,
            'is_published' => false,
        ]);
    }

    public function inMemo(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Product::STATUS_IN_MEMO,
            'is_published' => false,
        ]);
    }

    public function inRepair(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Product::STATUS_IN_REPAIR,
            'is_published' => false,
        ]);
    }

    public function withVariants(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_variants' => true,
        ]);
    }
}
