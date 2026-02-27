<?php

namespace Database\Factories;

use App\Models\StorefrontChatSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StorefrontChatMessage>
 */
class StorefrontChatMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'storefront_chat_session_id' => StorefrontChatSession::factory(),
            'role' => 'user',
            'content' => $this->faker->sentence(),
            'tokens_used' => 0,
        ];
    }

    public function assistant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'assistant',
            'tokens_used' => $this->faker->numberBetween(50, 500),
        ]);
    }

    public function user(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'user',
        ]);
    }
}
