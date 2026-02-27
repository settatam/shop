<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use App\Models\NotificationLayout;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationLayout>
 */
class NotificationLayoutFactory extends Factory
{
    protected $model = NotificationLayout::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->words(3, true).' Layout',
            'slug' => fake()->unique()->slug(3),
            'channel' => NotificationChannel::TYPE_EMAIL,
            'content' => '{{ body|raw }}',
            'description' => fake()->sentence(),
            'is_default' => false,
            'is_system' => false,
            'is_enabled' => true,
        ];
    }

    public function email(): static
    {
        return $this->state(fn () => [
            'channel' => NotificationChannel::TYPE_EMAIL,
            'content' => NotificationLayout::getDefaultEmailLayoutContent(),
        ]);
    }

    public function sms(): static
    {
        return $this->state(fn () => [
            'channel' => NotificationChannel::TYPE_SMS,
            'content' => '{{ body|raw }} - {{ store.name }}',
        ]);
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'is_default' => true,
        ]);
    }

    public function system(): static
    {
        return $this->state(fn () => [
            'is_system' => true,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'is_enabled' => false,
        ]);
    }
}
