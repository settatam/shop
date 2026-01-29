<?php

namespace Database\Factories;

use App\Models\Status;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StatusTransition>
 */
class StatusTransitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_status_id' => Status::factory(),
            'to_status_id' => Status::factory(),
            'name' => fake()->optional()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'conditions' => null,
            'required_fields' => null,
            'is_enabled' => true,
        ];
    }

    /**
     * Set conditions for the transition.
     */
    public function withConditions(array $conditions): static
    {
        return $this->state(fn (array $attributes) => [
            'conditions' => $conditions,
        ]);
    }

    /**
     * Set required fields for the transition.
     */
    public function withRequiredFields(array $fields): static
    {
        return $this->state(fn (array $attributes) => [
            'required_fields' => $fields,
        ]);
    }

    /**
     * Mark as disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => false,
        ]);
    }
}
