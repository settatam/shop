<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Memo;
use App\Models\MemoItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MemoItem>
 */
class MemoItemFactory extends Factory
{
    protected $model = MemoItem::class;

    public function definition(): array
    {
        $cost = fake()->randomFloat(2, 50, 500);

        return [
            'memo_id' => Memo::factory(),
            'product_id' => null,
            'category_id' => null,
            'sku' => fake()->optional()->bothify('MEM-????-####'),
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'price' => $cost * fake()->randomFloat(2, 1.3, 2.0),
            'cost' => $cost,
            'tenor' => null,
            'due_date' => null,
            'is_returned' => false,
            'charge_taxes' => true,
        ];
    }

    public function returned(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_returned' => true,
        ]);
    }

    public function withCategory(): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => Category::factory(),
        ]);
    }

    public function withTenor(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'tenor' => $days,
            'due_date' => now()->addDays($days),
        ]);
    }

    public function withDueDate(\DateTimeInterface $date): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => $date,
        ]);
    }

    public function noTax(): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_taxes' => false,
        ]);
    }

    public function withPricing(float $cost, float $price): static
    {
        return $this->state(fn (array $attributes) => [
            'cost' => $cost,
            'price' => $price,
        ]);
    }
}
