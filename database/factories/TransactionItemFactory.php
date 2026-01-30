<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TransactionItem>
 */
class TransactionItemFactory extends Factory
{
    protected $model = TransactionItem::class;

    public function definition(): array
    {
        $price = fake()->randomFloat(2, 20, 300);

        return [
            'transaction_id' => Transaction::factory(),
            'category_id' => null,
            'product_id' => null,
            'sku' => fake()->optional()->bothify('TXN-????-####'),
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'quantity' => 1,
            'price' => $price,
            'buy_price' => $price * fake()->randomFloat(2, 0.4, 0.7),
            'dwt' => null,
            'precious_metal' => null,
            'condition' => fake()->randomElement([
                TransactionItem::CONDITION_NEW,
                TransactionItem::CONDITION_LIKE_NEW,
                TransactionItem::CONDITION_USED,
            ]),
            'is_added_to_inventory' => false,
            'date_added_to_inventory' => null,
        ];
    }

    public function preciousMetal(?string $type = null): static
    {
        return $this->state(fn (array $attributes) => [
            'precious_metal' => $type ?? fake()->randomElement([
                TransactionItem::METAL_GOLD_10K,
                TransactionItem::METAL_GOLD_14K,
                TransactionItem::METAL_GOLD_18K,
                TransactionItem::METAL_SILVER,
                TransactionItem::METAL_PLATINUM,
            ]),
            'dwt' => fake()->randomFloat(4, 0.5, 10),
        ]);
    }

    public function gold10k(): static
    {
        return $this->preciousMetal(TransactionItem::METAL_GOLD_10K);
    }

    public function gold14k(): static
    {
        return $this->preciousMetal(TransactionItem::METAL_GOLD_14K);
    }

    public function gold18k(): static
    {
        return $this->preciousMetal(TransactionItem::METAL_GOLD_18K);
    }

    public function silver(): static
    {
        return $this->preciousMetal(TransactionItem::METAL_SILVER);
    }

    public function platinum(): static
    {
        return $this->preciousMetal(TransactionItem::METAL_PLATINUM);
    }

    public function addedToInventory(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_added_to_inventory' => true,
            'date_added_to_inventory' => now(),
        ]);
    }

    public function withCategory(): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => Category::factory(),
        ]);
    }

    public function damaged(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition' => TransactionItem::CONDITION_DAMAGED,
        ]);
    }
}
