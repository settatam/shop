<?php

namespace Database\Factories;

use App\Models\QuickEvaluation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuickEvaluation>
 */
class QuickEvaluationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'user_id' => User::factory(),
            'title' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'precious_metal' => $this->faker->randomElement(['10k_gold', '14k_gold', '18k_gold', 'silver', 'platinum']),
            'condition' => $this->faker->randomElement(['new', 'like_new', 'used', 'damaged']),
            'estimated_weight' => $this->faker->randomFloat(2, 0.5, 50),
            'estimated_value' => $this->faker->randomFloat(2, 50, 5000),
            'status' => QuickEvaluation::STATUS_DRAFT,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QuickEvaluation::STATUS_DRAFT,
        ]);
    }

    public function converted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QuickEvaluation::STATUS_CONVERTED,
        ]);
    }

    public function discarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QuickEvaluation::STATUS_DISCARDED,
        ]);
    }
}
