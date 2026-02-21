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
            'edition' => config('editions.default', 'shopmata-public'),
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

    /**
     * Configure the store to have online buys workflow enabled.
     */
    public function withOnlineBuysWorkflow(): static
    {
        return $this->state(fn (array $attributes) => [
            'metal_price_settings' => ['online_buys_workflow' => true],
        ]);
    }

    /**
     * Set a specific edition for the store.
     */
    public function withEdition(string $edition): static
    {
        return $this->state(fn (array $attributes) => [
            'edition' => $edition,
        ]);
    }

    /**
     * Create a store with the standard edition (full features).
     */
    public function standard(): static
    {
        return $this->withEdition('standard');
    }

    /**
     * Create a store with the pawn shop edition.
     */
    public function pawnShop(): static
    {
        return $this->withEdition('pawn_shop');
    }

    /**
     * Create a store with the legacy edition.
     */
    public function legacy(): static
    {
        return $this->withEdition('legacy');
    }

    /**
     * Create a store with the shopmata-public edition (new default).
     */
    public function shopmataPublic(): static
    {
        return $this->withEdition('shopmata-public');
    }
}
