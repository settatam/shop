<?php

namespace Database\Factories;

use App\Models\Bucket;
use App\Models\BucketItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BucketItem>
 */
class BucketItemFactory extends Factory
{
    protected $model = BucketItem::class;

    public function definition(): array
    {
        return [
            'bucket_id' => Bucket::factory(),
            'transaction_item_id' => null,
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'value' => fake()->randomFloat(2, 10, 200),
            'sold_at' => null,
            'order_item_id' => null,
        ];
    }

    public function sold(): static
    {
        return $this->state(fn (array $attributes) => [
            'sold_at' => now(),
        ]);
    }
}
