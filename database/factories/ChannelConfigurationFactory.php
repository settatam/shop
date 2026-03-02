<?php

namespace Database\Factories;

use App\Enums\ConversationChannel;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChannelConfiguration>
 */
class ChannelConfigurationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'channel' => ConversationChannel::WhatsApp,
            'credentials' => [
                'phone_number_id' => $this->faker->numerify('###############'),
                'access_token' => $this->faker->sha256(),
            ],
            'is_active' => false,
        ];
    }

    public function whatsapp(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ConversationChannel::WhatsApp,
            'credentials' => [
                'phone_number_id' => $this->faker->numerify('###############'),
                'access_token' => $this->faker->sha256(),
            ],
        ]);
    }

    public function slack(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ConversationChannel::Slack,
            'credentials' => [
                'bot_token' => 'xoxb-'.$this->faker->sha256(),
            ],
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }
}
