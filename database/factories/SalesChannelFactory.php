<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SalesChannel>
 */
class SalesChannelFactory extends Factory
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
            'name' => $this->faker->words(2, true),
            'code' => $this->faker->unique()->slug(2),
            'type' => 'local',
            'is_local' => false,
            'is_active' => true,
            'is_default' => false,
            'sort_order' => $this->faker->numberBetween(1, 10),
        ];
    }

    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'local',
            'is_local' => true,
            'code' => 'in_store',
            'name' => 'In Store',
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
