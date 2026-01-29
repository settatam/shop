<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category = fake()->randomElement(['orders', 'products', 'inventory', 'customers', 'team']);
        $channel = fake()->randomElement(NotificationChannel::TYPES);

        return [
            'store_id' => Store::factory(),
            'name' => fake()->words(3, true).' Notification',
            'slug' => fake()->unique()->slug(3),
            'description' => fake()->sentence(),
            'channel' => $channel,
            'subject' => $channel === 'email' ? fake()->sentence() : null,
            'content' => '<p>Hello {{ customer.name }},</p><p>'.fake()->paragraph().'</p>',
            'available_variables' => ['customer', 'store', 'order'],
            'category' => $category,
            'is_system' => false,
            'is_enabled' => true,
        ];
    }

    public function email(): static
    {
        return $this->state(fn () => [
            'channel' => NotificationChannel::TYPE_EMAIL,
            'subject' => fake()->sentence(),
        ]);
    }

    public function sms(): static
    {
        return $this->state(fn () => [
            'channel' => NotificationChannel::TYPE_SMS,
            'subject' => null,
            'content' => fake()->sentence(),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'is_enabled' => false,
        ]);
    }

    public function system(): static
    {
        return $this->state(fn () => [
            'is_system' => true,
        ]);
    }
}
