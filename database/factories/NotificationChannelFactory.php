<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    protected $model = NotificationChannel::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'type' => fake()->randomElement(NotificationChannel::TYPES),
            'name' => fake()->word().' Channel',
            'settings' => [],
            'is_enabled' => true,
            'is_default' => false,
        ];
    }

    public function email(): static
    {
        return $this->state(fn () => [
            'type' => NotificationChannel::TYPE_EMAIL,
            'name' => 'Email',
            'settings' => [
                'from_name' => fake()->company(),
                'from_email' => fake()->safeEmail(),
            ],
        ]);
    }

    public function sms(): static
    {
        return $this->state(fn () => [
            'type' => NotificationChannel::TYPE_SMS,
            'name' => 'SMS',
            'settings' => [
                'provider' => 'twilio',
                'from_number' => fake()->phoneNumber(),
            ],
        ]);
    }

    public function slack(): static
    {
        return $this->state(fn () => [
            'type' => NotificationChannel::TYPE_SLACK,
            'name' => 'Slack',
            'settings' => [
                'webhook_url' => 'https://hooks.slack.com/services/'.fake()->uuid(),
            ],
        ]);
    }

    public function webhook(): static
    {
        return $this->state(fn () => [
            'type' => NotificationChannel::TYPE_WEBHOOK,
            'name' => 'Webhook',
            'settings' => [
                'url' => fake()->url(),
            ],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'is_enabled' => false,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'is_default' => true,
        ]);
    }
}
