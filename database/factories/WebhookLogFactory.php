<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\WebhookLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WebhookLog>
 */
class WebhookLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_marketplace_id' => StoreMarketplace::factory(),
            'store_id' => Store::factory(),
            'platform' => fake()->randomElement(Platform::cases()),
            'event_type' => fake()->randomElement([
                'orders/create',
                'orders/updated',
                'orders/paid',
                'order.created',
            ]),
            'external_id' => fake()->uuid(),
            'status' => WebhookLog::STATUS_PENDING,
            'error_message' => null,
            'retry_count' => 0,
            'headers' => [
                'content-type' => 'application/json',
            ],
            'payload' => $this->generateSamplePayload(),
            'response' => null,
            'ip_address' => fake()->ipv4(),
            'signature' => fake()->sha256(),
            'processed_at' => null,
        ];
    }

    protected function generateSamplePayload(): array
    {
        return [
            'id' => fake()->numberBetween(1000000, 9999999),
            'order_number' => '#'.fake()->numerify('####'),
            'total_price' => fake()->randomFloat(2, 20, 500),
            'subtotal_price' => fake()->randomFloat(2, 20, 400),
            'currency' => 'USD',
            'financial_status' => 'paid',
            'fulfillment_status' => null,
            'email' => fake()->email(),
            'created_at' => now()->toIso8601String(),
            'line_items' => [
                [
                    'id' => fake()->numberBetween(1000000, 9999999),
                    'title' => fake()->words(3, true),
                    'quantity' => fake()->numberBetween(1, 3),
                    'price' => fake()->randomFloat(2, 10, 100),
                ],
            ],
        ];
    }

    public function shopify(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Shopify,
            'event_type' => 'orders/create',
            'headers' => [
                'content-type' => 'application/json',
                'x-shopify-topic' => 'orders/create',
                'x-shopify-hmac-sha256' => fake()->sha256(),
            ],
        ]);
    }

    public function ebay(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Ebay,
            'event_type' => 'order.created',
        ]);
    }

    public function amazon(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Amazon,
            'event_type' => 'ORDER_CHANGE',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebhookLog::STATUS_PENDING,
            'processed_at' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebhookLog::STATUS_PROCESSING,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebhookLog::STATUS_COMPLETED,
            'processed_at' => now(),
            'response' => ['order_id' => fake()->numberBetween(1, 1000)],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebhookLog::STATUS_FAILED,
            'error_message' => 'Failed to process webhook',
            'retry_count' => 1,
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebhookLog::STATUS_SKIPPED,
            'error_message' => 'Event type not processed',
            'processed_at' => now(),
        ]);
    }
}
