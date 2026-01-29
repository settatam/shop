<?php

namespace App\Services\Notifications\Contracts;

use App\Models\NotificationLog;

interface NotificationDriverInterface
{
    /**
     * Send a notification.
     *
     * @param  string  $recipient  Email, phone number, device token, etc.
     * @param  string  $content  The rendered notification content.
     * @param  array  $options  Additional options (subject for email, etc.)
     * @return NotificationLog The log entry for this notification.
     */
    public function send(string $recipient, string $content, array $options = []): NotificationLog;

    /**
     * Get the channel type identifier.
     */
    public function getType(): string;

    /**
     * Check if the driver is properly configured.
     */
    public function isConfigured(): bool;
}
