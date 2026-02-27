<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\StoreMarketplace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StorefrontChatSession>
 */
class StorefrontChatSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'store_marketplace_id' => StoreMarketplace::factory(),
            'visitor_id' => Str::uuid()->toString(),
            'title' => null,
            'last_message_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subHour(),
            'last_message_at' => now()->subHours(2),
        ]);
    }
}
