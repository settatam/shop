<?php

namespace Database\Factories;

use App\Enums\StatusableType;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Status>
 */
class StatusFactory extends Factory
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
            'entity_type' => fake()->randomElement(StatusableType::cases())->value,
            'name' => fake()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'color' => fake()->hexColor(),
            'icon' => null,
            'description' => fake()->optional()->sentence(),
            'is_default' => false,
            'is_final' => false,
            'is_system' => false,
            'sort_order' => fake()->numberBetween(0, 100),
            'behavior' => [],
        ];
    }

    /**
     * Create a status for transactions.
     */
    public function forTransaction(): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => StatusableType::Transaction->value,
        ]);
    }

    /**
     * Create a status for orders.
     */
    public function forOrder(): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => StatusableType::Order->value,
        ]);
    }

    /**
     * Create a status for repairs.
     */
    public function forRepair(): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => StatusableType::Repair->value,
        ]);
    }

    /**
     * Create a status for memos.
     */
    public function forMemo(): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => StatusableType::Memo->value,
        ]);
    }

    /**
     * Mark as default status.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * Mark as final status.
     */
    public function final(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_final' => true,
        ]);
    }

    /**
     * Mark as system status.
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => true,
        ]);
    }

    /**
     * Set specific behavior flags.
     *
     * @param  array<string, bool>  $behavior
     */
    public function withBehavior(array $behavior): static
    {
        return $this->state(fn (array $attributes) => [
            'behavior' => $behavior,
        ]);
    }

    /**
     * Create a pending status.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Pending',
            'slug' => 'pending',
            'color' => '#f59e0b',
            'is_default' => true,
            'behavior' => ['allows_cancellation' => true],
        ]);
    }

    /**
     * Create a completed status.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Completed',
            'slug' => 'completed',
            'color' => '#22c55e',
            'is_final' => true,
        ]);
    }

    /**
     * Create a cancelled status.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Cancelled',
            'slug' => 'cancelled',
            'color' => '#6b7280',
            'is_final' => true,
        ]);
    }
}
