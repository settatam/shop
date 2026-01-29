<?php

namespace Database\Factories;

use App\Models\Bucket;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Bucket>
 */
class BucketFactory extends Factory
{
    protected $model = Bucket::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'total_value' => 0,
        ];
    }

    public function withValue(float $value): static
    {
        return $this->state(fn (array $attributes) => [
            'total_value' => $value,
        ]);
    }
}
