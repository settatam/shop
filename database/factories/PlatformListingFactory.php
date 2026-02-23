<?php

namespace Database\Factories;

use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\SalesChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlatformListing>
 */
class PlatformListingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sales_channel_id' => SalesChannel::factory(),
            'status' => PlatformListing::STATUS_NOT_LISTED,
            'platform_price' => $this->faker->randomFloat(2, 10, 1000),
            'platform_quantity' => $this->faker->numberBetween(0, 100),
        ];
    }

    public function listed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PlatformListing::STATUS_LISTED,
            'published_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    public function notListed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PlatformListing::STATUS_NOT_LISTED,
            'published_at' => null,
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PlatformListing::STATUS_ENDED,
            'published_at' => now()->subDays(7),
            'last_synced_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PlatformListing::STATUS_ARCHIVED,
        ]);
    }

    /**
     * @deprecated Use listed() instead
     */
    public function active(): static
    {
        return $this->listed();
    }

    /**
     * @deprecated Use notListed() instead
     */
    public function draft(): static
    {
        return $this->notListed();
    }

    public function withError(string $message = 'Sync error'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PlatformListing::STATUS_ERROR,
            'last_error' => $message,
        ]);
    }
}
