<?php

namespace Database\Factories;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
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
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_message_at' => now()->subHours(2),
        ]);
    }

    public function assigned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConversationStatus::Assigned,
            'assigned_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConversationStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    public function whatsapp(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ConversationChannel::WhatsApp,
        ]);
    }

    public function slack(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ConversationChannel::Slack,
        ]);
    }
}
