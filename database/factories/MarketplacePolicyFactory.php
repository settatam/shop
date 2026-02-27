<?php

namespace Database\Factories;

use App\Models\MarketplacePolicy;
use App\Models\Store;
use App\Models\StoreMarketplace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MarketplacePolicy>
 */
class MarketplacePolicyFactory extends Factory
{
    protected $model = MarketplacePolicy::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'store_marketplace_id' => StoreMarketplace::factory(),
            'type' => fake()->randomElement(MarketplacePolicy::TYPES),
            'external_id' => fake()->uuid(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'details' => [],
            'is_default' => false,
        ];
    }

    public function return(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MarketplacePolicy::TYPE_RETURN,
        ]);
    }

    public function payment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MarketplacePolicy::TYPE_PAYMENT,
        ]);
    }

    public function fulfillment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MarketplacePolicy::TYPE_FULFILLMENT,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
