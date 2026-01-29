<?php

namespace Database\Factories;

use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationSubscription>
 */
class NotificationSubscriptionFactory extends Factory
{
    protected $model = NotificationSubscription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'notification_template_id' => NotificationTemplate::factory(),
            'activity' => fake()->randomElement([
                'order.created',
                'order.updated',
                'order.fulfilled',
                'product.created',
                'product.updated',
                'customer.created',
                'inventory.low_stock',
            ]),
            'name' => fake()->words(3, true).' Trigger',
            'description' => fake()->sentence(),
            'conditions' => [],
            'recipients' => [NotificationSubscription::RECIPIENT_OWNER],
            'schedule_type' => NotificationSubscription::SCHEDULE_IMMEDIATE,
            'delay_minutes' => null,
            'delay_unit' => null,
            'is_enabled' => true,
        ];
    }

    public function immediate(): static
    {
        return $this->state(fn () => [
            'schedule_type' => NotificationSubscription::SCHEDULE_IMMEDIATE,
            'delay_minutes' => null,
            'delay_unit' => null,
        ]);
    }

    public function delayed(int $minutes = 30): static
    {
        return $this->state(fn () => [
            'schedule_type' => NotificationSubscription::SCHEDULE_DELAYED,
            'delay_minutes' => $minutes,
            'delay_unit' => 'minutes',
        ]);
    }

    public function forOwner(): static
    {
        return $this->state(fn () => [
            'recipients' => [NotificationSubscription::RECIPIENT_OWNER],
        ]);
    }

    public function forCustomer(): static
    {
        return $this->state(fn () => [
            'recipients' => [NotificationSubscription::RECIPIENT_CUSTOMER],
        ]);
    }

    public function forStaff(): static
    {
        return $this->state(fn () => [
            'recipients' => [NotificationSubscription::RECIPIENT_STAFF],
        ]);
    }

    public function withConditions(array $conditions): static
    {
        return $this->state(fn () => [
            'conditions' => $conditions,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'is_enabled' => false,
        ]);
    }
}
