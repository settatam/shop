<?php

namespace App\Services\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailDriver extends AbstractNotificationDriver
{
    public function getType(): string
    {
        return NotificationChannel::TYPE_EMAIL;
    }

    public function isConfigured(): bool
    {
        // Email uses Laravel's default mail configuration
        return true;
    }

    public function send(string $recipient, string $content, array $options = []): NotificationLog
    {
        $log = $this->createLog($recipient, $content, $options);
        $subject = $options['subject'] ?? 'Notification from '.$this->store->name;

        if (empty($recipient)) {
            $log->markAsFailed('Recipient email address is empty');

            return $log;
        }

        try {
            // Redirect to developer email in non-production
            $to = config('app.env') !== 'production'
                ? config('mail.developer_email', $recipient)
                : $recipient;

            $fromEmail = $this->getSetting('from_email', config('mail.from.address'));
            $fromName = $this->getSetting('from_name', $this->store->name);

            if (empty($to)) {
                $log->markAsFailed('Resolved recipient email is empty');

                return $log;
            }

            if (empty($fromEmail)) {
                $log->markAsFailed('From email address is not configured');

                return $log;
            }

            Mail::html($content, function ($message) use ($to, $subject, $fromEmail, $fromName, $options) {
                $message->to($to)
                    ->subject($subject);

                $message->from($fromEmail, $fromName);

                // Add reply-to if specified
                if ($replyTo = $this->getSetting('reply_to')) {
                    $message->replyTo($replyTo);
                }

                // Add attachments if any
                if (! empty($options['attachments'])) {
                    foreach ($options['attachments'] as $attachment) {
                        if (is_array($attachment)) {
                            $message->attach($attachment['path'], [
                                'as' => $attachment['name'] ?? null,
                                'mime' => $attachment['mime'] ?? null,
                            ]);
                        } else {
                            $message->attach($attachment);
                        }
                    }
                }
            });

            $log->markAsSent();
        } catch (\Exception $e) {
            Log::error('Failed to send email notification', [
                'recipient' => $recipient,
                'error' => $e->getMessage(),
                'store_id' => $this->store->id,
            ]);

            $log->markAsFailed($e->getMessage());
        }

        return $log;
    }
}
