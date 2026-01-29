<?php

namespace App\Services\Statuses;

use App\Models\NotificationTemplate;
use App\Models\Status;
use App\Models\StatusAutomation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StatusAutomationExecutor
{
    /**
     * Execute an automation.
     */
    public function execute(StatusAutomation $automation, Model $entity, ?Status $from, Status $to): void
    {
        try {
            match ($automation->action_type) {
                StatusAutomation::ACTION_NOTIFICATION => $this->executeNotification($automation, $entity),
                StatusAutomation::ACTION_WEBHOOK => $this->executeWebhook($automation, $entity, $from, $to),
                StatusAutomation::ACTION_CUSTOM => $this->executeCustomAction($automation, $entity, $from, $to),
                default => Log::warning("Unknown automation action type: {$automation->action_type}"),
            };
        } catch (\Exception $e) {
            Log::error('Status automation execution failed', [
                'automation_id' => $automation->id,
                'entity_type' => get_class($entity),
                'entity_id' => $entity->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Execute a notification automation.
     */
    protected function executeNotification(StatusAutomation $automation, Model $entity): void
    {
        $templateId = $automation->getNotificationTemplateId();
        $recipients = $automation->getNotificationRecipients();

        if (! $templateId) {
            Log::warning('Notification automation missing template_id', ['automation_id' => $automation->id]);

            return;
        }

        $template = NotificationTemplate::find($templateId);

        if (! $template) {
            Log::warning('Notification template not found', ['template_id' => $templateId]);

            return;
        }

        // Resolve recipients
        $resolvedRecipients = $this->resolveRecipients($recipients, $entity);

        // Queue notifications for each recipient
        foreach ($resolvedRecipients as $recipient) {
            // Queue the notification using the notification system
            // This integrates with your existing NotificationService
            dispatch(function () use ($template, $entity, $recipient) {
                // The actual notification sending logic would go here
                // Using your existing notification infrastructure
                Log::info('Notification queued', [
                    'template_id' => $template->id,
                    'entity_type' => get_class($entity),
                    'entity_id' => $entity->getKey(),
                    'recipient' => $recipient,
                ]);
            })->afterCommit();
        }
    }

    /**
     * Execute a webhook automation.
     */
    protected function executeWebhook(StatusAutomation $automation, Model $entity, ?Status $from, Status $to): void
    {
        $url = $automation->getWebhookUrl();

        if (! $url) {
            Log::warning('Webhook automation missing URL', ['automation_id' => $automation->id]);

            return;
        }

        $method = $automation->getWebhookMethod();
        $headers = $automation->getWebhookHeaders();

        $payload = [
            'event' => 'status_changed',
            'entity_type' => $this->getEntityTypeName($entity),
            'entity_id' => $entity->getKey(),
            'from_status' => $from?->slug,
            'to_status' => $to->slug,
            'timestamp' => now()->toIso8601String(),
            'data' => $this->getEntityData($entity),
        ];

        // Execute webhook asynchronously
        dispatch(function () use ($url, $method, $headers, $payload, $automation) {
            try {
                $response = Http::withHeaders($headers)
                    ->timeout(30)
                    ->$method($url, $payload);

                Log::info('Webhook executed', [
                    'automation_id' => $automation->id,
                    'url' => $url,
                    'status' => $response->status(),
                ]);
            } catch (\Exception $e) {
                Log::error('Webhook execution failed', [
                    'automation_id' => $automation->id,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterCommit();
    }

    /**
     * Execute a custom action automation.
     */
    protected function executeCustomAction(StatusAutomation $automation, Model $entity, ?Status $from, Status $to): void
    {
        $action = $automation->getCustomAction();
        $params = $automation->getCustomActionParams();

        if (! $action) {
            Log::warning('Custom action automation missing action name', ['automation_id' => $automation->id]);

            return;
        }

        // Execute custom actions
        match ($action) {
            'mark_paid' => $this->executeMarkPaid($entity, $params),
            'send_email' => $this->executeSendEmail($entity, $params),
            'update_inventory' => $this->executeUpdateInventory($entity, $params),
            'create_invoice' => $this->executeCreateInvoice($entity, $params),
            default => Log::warning("Unknown custom action: {$action}", ['automation_id' => $automation->id]),
        };
    }

    /**
     * Resolve recipients from recipient identifiers.
     *
     * @param  array<string>  $recipients
     * @return array<string>
     */
    protected function resolveRecipients(array $recipients, Model $entity): array
    {
        $resolved = [];

        foreach ($recipients as $recipient) {
            $emails = match ($recipient) {
                'owner' => $this->getOwnerEmails($entity),
                'customer' => $this->getCustomerEmail($entity),
                'vendor' => $this->getVendorEmail($entity),
                'assigned_user' => $this->getAssignedUserEmail($entity),
                default => str_contains($recipient, '@') ? [$recipient] : [],
            };

            $resolved = array_merge($resolved, $emails);
        }

        return array_unique(array_filter($resolved));
    }

    /**
     * Get owner emails for the entity's store.
     *
     * @return array<string>
     */
    protected function getOwnerEmails(Model $entity): array
    {
        if (! method_exists($entity, 'store') || ! $entity->store) {
            return [];
        }

        $owner = $entity->store->storeUsers()
            ->where('is_owner', true)
            ->with('user')
            ->first();

        return $owner?->user?->email ? [$owner->user->email] : [];
    }

    /**
     * Get customer email from entity.
     *
     * @return array<string>
     */
    protected function getCustomerEmail(Model $entity): array
    {
        if (! method_exists($entity, 'customer') || ! $entity->customer) {
            return [];
        }

        return $entity->customer->email ? [$entity->customer->email] : [];
    }

    /**
     * Get vendor email from entity.
     *
     * @return array<string>
     */
    protected function getVendorEmail(Model $entity): array
    {
        if (! method_exists($entity, 'vendor') || ! $entity->vendor) {
            return [];
        }

        return $entity->vendor->email ? [$entity->vendor->email] : [];
    }

    /**
     * Get assigned user email from entity.
     *
     * @return array<string>
     */
    protected function getAssignedUserEmail(Model $entity): array
    {
        // Check for assigned_to relationship
        if (method_exists($entity, 'assignedUser') && $entity->assignedUser) {
            return $entity->assignedUser->email ? [$entity->assignedUser->email] : [];
        }

        // Check for user relationship
        if (method_exists($entity, 'user') && $entity->user) {
            return $entity->user->email ? [$entity->user->email] : [];
        }

        return [];
    }

    /**
     * Get entity type name for webhook payload.
     */
    protected function getEntityTypeName(Model $entity): string
    {
        return strtolower(class_basename($entity));
    }

    /**
     * Get entity data for webhook payload.
     *
     * @return array<string, mixed>
     */
    protected function getEntityData(Model $entity): array
    {
        // Return basic attributes, avoiding relationships
        $data = [
            'id' => $entity->getKey(),
        ];

        // Add identifier field based on entity type
        if (property_exists($entity, 'transaction_number') || isset($entity->transaction_number)) {
            $data['transaction_number'] = $entity->transaction_number;
        }
        if (property_exists($entity, 'invoice_number') || isset($entity->invoice_number)) {
            $data['invoice_number'] = $entity->invoice_number;
        }
        if (property_exists($entity, 'repair_number') || isset($entity->repair_number)) {
            $data['repair_number'] = $entity->repair_number;
        }
        if (property_exists($entity, 'memo_number') || isset($entity->memo_number)) {
            $data['memo_number'] = $entity->memo_number;
        }

        return $data;
    }

    /**
     * Custom action: Mark as paid.
     *
     * @param  array<string, mixed>  $params
     */
    protected function executeMarkPaid(Model $entity, array $params): void
    {
        // Implementation depends on entity type
        Log::info('Custom action: mark_paid', ['entity_id' => $entity->getKey()]);
    }

    /**
     * Custom action: Send email.
     *
     * @param  array<string, mixed>  $params
     */
    protected function executeSendEmail(Model $entity, array $params): void
    {
        // Implementation for sending custom emails
        Log::info('Custom action: send_email', ['entity_id' => $entity->getKey(), 'params' => $params]);
    }

    /**
     * Custom action: Update inventory.
     *
     * @param  array<string, mixed>  $params
     */
    protected function executeUpdateInventory(Model $entity, array $params): void
    {
        // Implementation for inventory updates
        Log::info('Custom action: update_inventory', ['entity_id' => $entity->getKey(), 'params' => $params]);
    }

    /**
     * Custom action: Create invoice.
     *
     * @param  array<string, mixed>  $params
     */
    protected function executeCreateInvoice(Model $entity, array $params): void
    {
        // Implementation for creating invoices
        Log::info('Custom action: create_invoice', ['entity_id' => $entity->getKey(), 'params' => $params]);
    }
}
