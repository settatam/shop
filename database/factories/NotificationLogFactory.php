<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $channel = fake()->randomElement(NotificationChannel::TYPES);
        $status = fake()->randomElement([
            NotificationLog::STATUS_PENDING,
            NotificationLog::STATUS_SENT,
            NotificationLog::STATUS_DELIVERED,
            NotificationLog::STATUS_FAILED,
        ]);

        return [
            'store_id' => Store::factory(),
            'notification_subscription_id' => null,
            'notification_template_id' => null,
            'channel' => $channel,
            'activity' => fake()->randomElement(['order.created', 'product.created', 'customer.created']),
            'recipient' => fake()->safeEmail(),
            'recipient_type' => 'email',
            'subject' => $channel === 'email' ? fake()->sentence() : null,
            'content' => fake()->paragraph(),
            'data' => [],
            'status' => $status,
            'error_message' => $status === NotificationLog::STATUS_FAILED ? fake()->sentence() : null,
            'sent_at' => in_array($status, [NotificationLog::STATUS_SENT, NotificationLog::STATUS_DELIVERED])
                ? fake()->dateTimeBetween('-1 week', 'now')
                : null,
            'delivered_at' => $status === NotificationLog::STATUS_DELIVERED
                ? fake()->dateTimeBetween('-1 week', 'now')
                : null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => NotificationLog::STATUS_PENDING,
            'sent_at' => null,
            'delivered_at' => null,
            'error_message' => null,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => NotificationLog::STATUS_SENT,
            'sent_at' => now(),
            'error_message' => null,
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn () => [
            'status' => NotificationLog::STATUS_DELIVERED,
            'sent_at' => now()->subMinutes(5),
            'delivered_at' => now(),
            'error_message' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => NotificationLog::STATUS_FAILED,
            'error_message' => fake()->sentence(),
            'sent_at' => null,
            'delivered_at' => null,
        ]);
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
            'recipient' => fake()->phoneNumber(),
            'recipient_type' => 'phone',
        ]);
    }
}
