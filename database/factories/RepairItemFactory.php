<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Repair;
use App\Models\RepairItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RepairItem>
 */
class RepairItemFactory extends Factory
{
    protected $model = RepairItem::class;

    public function definition(): array
    {
        $vendorCost = fake()->randomFloat(2, 20, 150);

        return [
            'repair_id' => Repair::factory(),
            'product_id' => null,
            'category_id' => null,
            'sku' => fake()->optional()->bothify('REP-????-####'),
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'vendor_cost' => $vendorCost,
            'customer_cost' => $vendorCost * fake()->randomFloat(2, 1.2, 2.0),
            'status' => RepairItem::STATUS_PENDING,
            'dwt' => null,
            'precious_metal' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RepairItem::STATUS_PENDING,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RepairItem::STATUS_IN_PROGRESS,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RepairItem::STATUS_COMPLETED,
        ]);
    }

    public function withCategory(): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => Category::factory(),
        ]);
    }

    public function preciousMetal(): static
    {
        return $this->state(fn (array $attributes) => [
            'precious_metal' => fake()->randomElement(['gold_14k', 'gold_18k', 'silver', 'platinum']),
            'dwt' => fake()->randomFloat(4, 0.5, 5),
        ]);
    }

    public function withCosts(float $vendorCost, float $customerCost): static
    {
        return $this->state(fn (array $attributes) => [
            'vendor_cost' => $vendorCost,
            'customer_cost' => $customerCost,
        ]);
    }
}
