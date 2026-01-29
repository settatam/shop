<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ReturnPolicy>
 */
class ReturnPolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->randomElement(['Standard Return Policy', '30-Day Return', 'No Questions Asked', 'Final Sale Policy']),
            'description' => fake()->optional()->sentence(),
            'return_window_days' => fake()->randomElement([14, 30, 60, 90]),
            'allow_refund' => true,
            'allow_store_credit' => true,
            'allow_exchange' => true,
            'restocking_fee_percent' => fake()->randomElement([0, 0, 0, 10, 15]),
            'require_receipt' => fake()->boolean(30),
            'require_original_packaging' => fake()->boolean(40),
            'excluded_conditions' => null,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function noRefunds(): static
    {
        return $this->state(fn (array $attributes) => [
            'allow_refund' => false,
            'allow_store_credit' => true,
            'allow_exchange' => true,
        ]);
    }

    public function exchangeOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'allow_refund' => false,
            'allow_store_credit' => false,
            'allow_exchange' => true,
        ]);
    }

    public function withRestockingFee(float $percent = 15): static
    {
        return $this->state(fn (array $attributes) => [
            'restocking_fee_percent' => $percent,
        ]);
    }

    public function shortWindow(int $days = 7): static
    {
        return $this->state(fn (array $attributes) => [
            'return_window_days' => $days,
        ]);
    }

    public function strictPolicy(): static
    {
        return $this->state(fn (array $attributes) => [
            'require_receipt' => true,
            'require_original_packaging' => true,
            'restocking_fee_percent' => 15,
            'return_window_days' => 14,
        ]);
    }
}
