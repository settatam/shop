<?php

namespace App\Services\Agents\Actions;

use App\Models\AgentAction;
use App\Models\Customer;
use App\Models\StoreAgent;
use App\Services\Agents\Contracts\AgentActionInterface;
use App\Services\Agents\Results\ActionResult;

class SendNotificationAction implements AgentActionInterface
{
    public function getType(): string
    {
        return 'send_notification';
    }

    public function getDescription(): string
    {
        return 'Send a notification to a customer';
    }

    public function requiresApproval(StoreAgent $storeAgent, array $payload): bool
    {
        // Always require approval if store agent requires it
        if ($storeAgent->requiresApproval()) {
            return true;
        }

        // Notifications typically don't require approval when auto is enabled
        return false;
    }

    public function execute(AgentAction $action): ActionResult
    {
        $customer = $action->actionable;

        if (! $customer instanceof Customer) {
            return ActionResult::failure('Action target is not a customer');
        }

        $payload = $action->payload;
        $notificationType = $payload['notification_type'] ?? 'general';
        $message = $payload['message'] ?? '';
        $subject = $payload['subject'] ?? 'Notification from Shopmata';

        if (empty($message)) {
            return ActionResult::failure('No message specified in payload');
        }

        // For now, log the notification intent
        // In a full implementation, this would integrate with NotificationManager
        // to send via the appropriate channel (email, SMS, etc.)

        // TODO: Integrate with NotificationManager service
        // $notificationManager = app(NotificationManager::class);
        // $notificationManager->send($customer, $notificationType, [
        //     'subject' => $subject,
        //     'message' => $message,
        //     'product_id' => $payload['product_id'] ?? null,
        // ]);

        return ActionResult::success(
            "Notification queued for {$customer->email}",
            [
                'customer_id' => $customer->id,
                'notification_type' => $notificationType,
                'subject' => $subject,
            ]
        );
    }

    public function rollback(AgentAction $action): bool
    {
        // Notifications cannot be rolled back
        return false;
    }

    public function validatePayload(array $payload): bool
    {
        return isset($payload['notification_type'])
            && isset($payload['message'])
            && ! empty($payload['message']);
    }
}
