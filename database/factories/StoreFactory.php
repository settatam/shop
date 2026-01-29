<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Store>
 */
class StoreFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => fake()->unique()->slug(),
            'business_name' => $name,
            'account_email' => fake()->companyEmail(),
            'customer_email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'zip' => fake()->postcode(),
            'is_active' => true,
            'default_tax_rate' => 0.08,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withJewelryModule(): static
    {
        return $this->state(fn (array $attributes) => [
            'jewelry_module_enabled' => true,
        ]);
    }

    public function withCustomProductModule(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_custom_product_module' => true,
        ]);
    }

    public function onboarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'step' => 2,
        ]);
    }

    public function withTaxRate(float $rate): static
    {
        return $this->state(fn (array $attributes) => [
            'default_tax_rate' => $rate,
        ]);
    }

    public function withTaxId(string $taxId): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_id_number' => $taxId,
        ]);
    }
}
