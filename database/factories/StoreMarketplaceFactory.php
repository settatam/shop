<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\Store;
use App\Models\StoreMarketplace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StoreMarketplace>
 */
class StoreMarketplaceFactory extends Factory
{
    protected $model = StoreMarketplace::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'platform' => fake()->randomElement(Platform::cases()),
            'name' => fake()->company(),
            'shop_domain' => fake()->domainName(),
            'external_store_id' => fake()->uuid(),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->sha256(),
            'token_expires_at' => now()->addDays(30),
            'credentials' => [
                'api_key' => fake()->sha256(),
                'api_secret' => fake()->sha256(),
                'webhook_secret' => fake()->sha256(),
            ],
            'settings' => [],
            'status' => 'active',
            'last_sync_at' => now(),
        ];
    }

    public function shopify(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Shopify,
            'name' => 'Shopify Store',
        ]);
    }

    public function ebay(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Ebay,
            'name' => 'eBay Store',
        ]);
    }

    public function amazon(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Amazon,
            'name' => 'Amazon Store',
        ]);
    }

    public function etsy(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Etsy,
            'name' => 'Etsy Shop',
        ]);
    }

    public function walmart(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Walmart,
            'name' => 'Walmart Store',
        ]);
    }

    public function woocommerce(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::WooCommerce,
            'name' => 'WooCommerce Store',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    public function withError(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'error',
            'last_error' => 'Token expired',
        ]);
    }
}
