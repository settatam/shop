<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AssistantDataGap>
 */
class AssistantDataGapFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'product_id' => Product::factory(),
            'field_name' => $this->faker->randomElement(['weight', 'hallmark', 'certification', 'gemstone_details', 'material']),
            'question_context' => $this->faker->sentence(),
            'occurrences' => $this->faker->numberBetween(1, 20),
            'last_occurred_at' => now(),
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'resolved_at' => now(),
        ]);
    }
}
