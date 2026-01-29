<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\StoreIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StoreIntegration>
 */
class StoreIntegrationFactory extends Factory
{
    protected $model = StoreIntegration::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'provider' => StoreIntegration::PROVIDER_PAYPAL,
            'name' => 'PayPal Integration',
            'environment' => StoreIntegration::ENV_SANDBOX,
            'access_token' => fake()->sha256(),
            'refresh_token' => null,
            'token_expires_at' => now()->addHours(8),
            'credentials' => [
                'client_id' => fake()->uuid(),
                'client_secret' => fake()->sha256(),
            ],
            'settings' => [],
            'status' => StoreIntegration::STATUS_ACTIVE,
            'last_used_at' => now(),
        ];
    }

    public function paypal(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => StoreIntegration::PROVIDER_PAYPAL,
            'name' => 'PayPal Integration',
        ]);
    }

    public function fedex(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => StoreIntegration::PROVIDER_FEDEX,
            'name' => 'FedEx Integration',
            'credentials' => [
                'client_id' => fake()->uuid(),
                'client_secret' => fake()->sha256(),
                'account_number' => fake()->numerify('##########'),
            ],
        ]);
    }

    public function quickbooks(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => StoreIntegration::PROVIDER_QUICKBOOKS,
            'name' => 'QuickBooks Integration',
        ]);
    }

    public function sandbox(): static
    {
        return $this->state(fn (array $attributes) => [
            'environment' => StoreIntegration::ENV_SANDBOX,
        ]);
    }

    public function production(): static
    {
        return $this->state(fn (array $attributes) => [
            'environment' => StoreIntegration::ENV_PRODUCTION,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StoreIntegration::STATUS_INACTIVE,
        ]);
    }

    public function withError(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StoreIntegration::STATUS_ERROR,
            'last_error' => 'API authentication failed',
        ]);
    }

    public function expiredToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'token_expires_at' => now()->subHour(),
        ]);
    }
}
