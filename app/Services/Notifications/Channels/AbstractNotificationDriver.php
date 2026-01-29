<?php

namespace App\Services\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Store;
use App\Services\Notifications\Contracts\NotificationDriverInterface;

abstract class AbstractNotificationDriver implements NotificationDriverInterface
{
    protected Store $store;

    protected ?NotificationChannel $channel;

    public function __construct(Store $store, ?NotificationChannel $channel = null)
    {
        $this->store = $store;
        $this->channel = $channel;
    }

    /**
     * Create a notification log entry.
     */
    protected function createLog(string $recipient, string $content, array $options = []): NotificationLog
    {
        return NotificationLog::create([
            'store_id' => $this->store->id,
            'notification_subscription_id' => $options['subscription_id'] ?? null,
            'notification_template_id' => $options['template_id'] ?? null,
            'channel' => $this->getType(),
            'direction' => $options['direction'] ?? NotificationLog::DIRECTION_OUTBOUND,
            'activity' => $options['activity'] ?? null,
            'recipient' => $recipient,
            'recipient_type' => $options['recipient_type'] ?? null,
            'notifiable_type' => $options['notifiable_type'] ?? null,
            'notifiable_id' => $options['notifiable_id'] ?? null,
            'recipient_model_type' => $options['recipient_model_type'] ?? null,
            'recipient_model_id' => $options['recipient_model_id'] ?? null,
            'subject' => $options['subject'] ?? null,
            'content' => $content,
            'data' => $options['data'] ?? null,
            'status' => NotificationLog::STATUS_PENDING,
        ]);
    }

    /**
     * Get a setting from the channel configuration.
     */
    protected function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->channel?->getSetting($key, $default) ?? $default;
    }
}
