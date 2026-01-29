<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Models\NotificationTemplate;
use App\Models\QueuedNotification;
use App\Models\Store;
use App\Services\Notifications\Channels\EmailDriver;
use App\Services\Notifications\Channels\PushDriver;
use App\Services\Notifications\Channels\SmsDriver;
use App\Services\Notifications\Contracts\NotificationDriverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NotificationManager
{
    protected Store $store;

    protected array $drivers = [];

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    /**
     * Get a notification driver for the given channel type.
     */
    public function driver(string $type): NotificationDriverInterface
    {
        if (! isset($this->drivers[$type])) {
            $channel = NotificationChannel::where('store_id', $this->store->id)
                ->where('type', $type)
                ->where('is_enabled', true)
                ->first();

            $this->drivers[$type] = match ($type) {
                NotificationChannel::TYPE_EMAIL => new EmailDriver($this->store, $channel),
                NotificationChannel::TYPE_SMS => new SmsDriver($this->store, $channel),
                NotificationChannel::TYPE_PUSH => new PushDriver($this->store, $channel),
                default => throw new \InvalidArgumentException("Unknown notification channel: {$type}"),
            };
        }

        return $this->drivers[$type];
    }

    /**
     * Trigger notifications for an activity.
     *
     * @param  string  $activity  The activity slug (e.g., 'products.create')
     * @param  array  $data  The context data for rendering templates
     * @param  Model|null  $subject  The model that triggered the activity
     */
    public function trigger(string $activity, array $data = [], ?Model $subject = null): Collection
    {
        $logs = collect();

        // Ensure store data is available
        $data['store'] = $this->store;
        $data['store']->load('owner');

        // Get all subscriptions for this activity
        $subscriptions = NotificationSubscription::forActivity($activity, $this->store->id);

        foreach ($subscriptions as $subscription) {
            // Check if conditions are met
            if (! $subscription->conditionsMet($data)) {
                continue;
            }

            // Check if immediate or should be queued
            if ($subscription->isImmediate()) {
                $logs = $logs->merge($this->sendSubscription($subscription, $data, $subject));
            } else {
                $this->queueSubscription($subscription, $data, $subject);
            }
        }

        return $logs;
    }

    /**
     * Send notifications for a subscription immediately.
     */
    public function sendSubscription(
        NotificationSubscription $subscription,
        array $data,
        ?Model $subject = null
    ): Collection {
        $logs = collect();
        $template = $subscription->template;

        if (! $template || ! $template->is_enabled) {
            return $logs;
        }

        // Render the template
        $content = $template->render($data);
        $subject_line = $template->renderSubject($data);

        // Get recipients
        $recipients = $subscription->getRecipientEmails($data);

        // Get the appropriate driver
        $driver = $this->driver($template->channel);

        foreach ($recipients as $recipient) {
            $options = [
                'subject' => $subject_line,
                'subscription_id' => $subscription->id,
                'template_id' => $template->id,
                'activity' => $subscription->activity,
                'data' => $data,
                'recipient_type' => $this->determineRecipientType($recipient, $data),
            ];

            if ($subject) {
                $options['notifiable_type'] = get_class($subject);
                $options['notifiable_id'] = $subject->getKey();
            }

            try {
                $log = $driver->send($recipient, $content, $options);
                $logs->push($log);
            } catch (\Exception $e) {
                Log::error('Failed to send notification', [
                    'activity' => $subscription->activity,
                    'recipient' => $recipient,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $logs;
    }

    /**
     * Queue a notification for later sending.
     */
    protected function queueSubscription(
        NotificationSubscription $subscription,
        array $data,
        ?Model $subject = null
    ): QueuedNotification {
        return QueuedNotification::create([
            'store_id' => $this->store->id,
            'notification_subscription_id' => $subscription->id,
            'notifiable_type' => $subject ? get_class($subject) : null,
            'notifiable_id' => $subject?->getKey(),
            'data' => $data,
            'scheduled_at' => $subscription->getScheduledTime(),
        ]);
    }

    /**
     * Process queued notifications that are ready to send.
     */
    public function processQueue(): Collection
    {
        $logs = collect();
        $ready = QueuedNotification::getReadyToSend()
            ->where('store_id', $this->store->id);

        foreach ($ready as $queued) {
            try {
                $subscription = $queued->subscription;

                if (! $subscription || ! $subscription->is_enabled) {
                    $queued->markAsFailed('Subscription not found or disabled');

                    continue;
                }

                $data = $queued->data;
                $data['store'] = $this->store;

                $sentLogs = $this->sendSubscription($subscription, $data, $queued->notifiable);
                $logs = $logs->merge($sentLogs);

                $queued->markAsSent();
            } catch (\Exception $e) {
                Log::error('Failed to process queued notification', [
                    'queued_id' => $queued->id,
                    'error' => $e->getMessage(),
                ]);

                $queued->markAsFailed($e->getMessage());
            }
        }

        return $logs;
    }

    /**
     * Send a one-off notification without a subscription.
     */
    public function send(
        string $channel,
        string $recipient,
        string $content,
        array $options = []
    ): NotificationLog {
        $driver = $this->driver($channel);

        return $driver->send($recipient, $content, $options);
    }

    /**
     * Send using a specific template.
     */
    public function sendTemplate(
        NotificationTemplate $template,
        string $recipient,
        array $data = [],
        ?Model $subject = null
    ): NotificationLog {
        $content = $template->render($data);
        $subject_line = $template->renderSubject($data);

        $options = [
            'subject' => $subject_line,
            'template_id' => $template->id,
            'data' => $data,
        ];

        if ($subject) {
            $options['notifiable_type'] = get_class($subject);
            $options['notifiable_id'] = $subject->getKey();
        }

        $driver = $this->driver($template->channel);

        return $driver->send($recipient, $content, $options);
    }

    /**
     * Determine the recipient type based on the email and context data.
     */
    protected function determineRecipientType(string $email, array $data): string
    {
        if (isset($data['customer']) && $data['customer']->email === $email) {
            return NotificationSubscription::RECIPIENT_CUSTOMER;
        }

        if (isset($data['store']['owner']) && $data['store']['owner']->email === $email) {
            return NotificationSubscription::RECIPIENT_OWNER;
        }

        return NotificationSubscription::RECIPIENT_CUSTOM;
    }

    /**
     * Get notification statistics for the store.
     */
    public function getStats(int $days = 30): array
    {
        $logs = NotificationLog::where('store_id', $this->store->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->get();

        return [
            'total' => $logs->count(),
            'sent' => $logs->where('status', NotificationLog::STATUS_SENT)->count(),
            'delivered' => $logs->where('status', NotificationLog::STATUS_DELIVERED)->count(),
            'failed' => $logs->whereIn('status', [
                NotificationLog::STATUS_FAILED,
                NotificationLog::STATUS_BOUNCED,
            ])->count(),
            'by_channel' => $logs->groupBy('channel')->map->count(),
            'by_activity' => $logs->whereNotNull('activity')->groupBy('activity')->map->count(),
        ];
    }
}
