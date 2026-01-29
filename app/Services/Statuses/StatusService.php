<?php

namespace App\Services\Statuses;

use App\Enums\StatusableType;
use App\Models\Status;
use App\Models\StatusAutomation;
use App\Models\StatusTransition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StatusService
{
    public function __construct(
        protected StatusAutomationExecutor $automationExecutor
    ) {}

    /**
     * Transition an entity to a new status.
     *
     * @param  array<string, mixed>  $data  Optional data for the transition
     */
    public function transitionEntity(Model $entity, Status $target, array $data = []): bool
    {
        /** @var Status|null $currentStatus */
        $currentStatus = $entity->statusModel;

        // If no current status, allow the transition
        if (! $currentStatus) {
            return $this->applyTransition($entity, null, $target, $data);
        }

        // Check if transition is allowed
        $transition = $this->getTransition($currentStatus, $target);

        if (! $transition || ! $transition->isAllowed($data)) {
            return false;
        }

        // Check for required fields
        $requiredFields = $transition->getRequiredFieldsConfig();
        foreach ($requiredFields as $field => $config) {
            if (($config['required'] ?? false) && empty($data[$field])) {
                return false;
            }
        }

        return $this->applyTransition($entity, $currentStatus, $target, $data);
    }

    /**
     * Apply a status transition.
     *
     * @param  array<string, mixed>  $data
     */
    protected function applyTransition(Model $entity, ?Status $from, Status $to, array $data = []): bool
    {
        return DB::transaction(function () use ($entity, $from, $to) {
            // Execute on_exit automations for old status
            if ($from) {
                $this->executeAutomations($from, StatusAutomation::TRIGGER_ON_EXIT, $entity, $from, $to);
            }

            // Update the entity
            $entity->status_id = $to->id;
            $entity->status = $to->slug; // Keep legacy field in sync
            $entity->save();

            // Execute on_enter automations for new status
            $this->executeAutomations($to, StatusAutomation::TRIGGER_ON_ENTER, $entity, $from, $to);

            return true;
        });
    }

    /**
     * Execute automations for a status trigger.
     */
    protected function executeAutomations(Status $status, string $trigger, Model $entity, ?Status $from, Status $to): void
    {
        $automations = $status->getEnabledAutomations($trigger);

        foreach ($automations as $automation) {
            $this->automationExecutor->execute($automation, $entity, $from, $to);
        }
    }

    /**
     * Get the transition between two statuses.
     */
    protected function getTransition(Status $from, Status $to): ?StatusTransition
    {
        return StatusTransition::query()
            ->where('from_status_id', $from->id)
            ->where('to_status_id', $to->id)
            ->where('is_enabled', true)
            ->first();
    }

    /**
     * Get all available statuses for a store and entity type.
     */
    public function getAvailableStatuses(int $storeId, StatusableType $type): Collection
    {
        return Status::query()
            ->where('store_id', $storeId)
            ->where('entity_type', $type->value)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Create default statuses for a store.
     */
    public function createDefaultStatuses(int $storeId, StatusableType $type): void
    {
        $definitions = $this->getDefaultStatusDefinitions($type);
        $statusMap = [];

        DB::transaction(function () use ($storeId, $type, $definitions, &$statusMap) {
            // Create statuses
            foreach ($definitions['statuses'] as $index => $definition) {
                $status = Status::create([
                    'store_id' => $storeId,
                    'entity_type' => $type->value,
                    'name' => $definition['name'],
                    'slug' => $definition['slug'],
                    'color' => $definition['color'],
                    'icon' => $definition['icon'] ?? null,
                    'description' => $definition['description'] ?? null,
                    'is_default' => $definition['is_default'] ?? false,
                    'is_final' => $definition['is_final'] ?? false,
                    'is_system' => $definition['is_system'] ?? true,
                    'sort_order' => $index,
                    'behavior' => $definition['behavior'] ?? [],
                ]);

                $statusMap[$definition['slug']] = $status;
            }

            // Create transitions
            foreach ($definitions['transitions'] as $transition) {
                $fromStatus = $statusMap[$transition['from']] ?? null;
                $toStatus = $statusMap[$transition['to']] ?? null;

                if ($fromStatus && $toStatus) {
                    StatusTransition::create([
                        'from_status_id' => $fromStatus->id,
                        'to_status_id' => $toStatus->id,
                        'name' => $transition['name'] ?? null,
                        'is_enabled' => true,
                    ]);
                }
            }
        });
    }

    /**
     * Get default status definitions for an entity type.
     *
     * @return array{statuses: array<array{name: string, slug: string, color: string, is_default?: bool, is_final?: bool, icon?: string, description?: string, behavior?: array<string, bool>}>, transitions: array<array{from: string, to: string, name?: string}>}
     */
    public function getDefaultStatusDefinitions(StatusableType $type): array
    {
        return match ($type) {
            StatusableType::Transaction => $this->getTransactionStatusDefinitions(),
            StatusableType::Order => $this->getOrderStatusDefinitions(),
            StatusableType::Repair => $this->getRepairStatusDefinitions(),
            StatusableType::Memo => $this->getMemoStatusDefinitions(),
        };
    }

    /**
     * Get transaction status definitions.
     */
    protected function getTransactionStatusDefinitions(): array
    {
        return [
            'statuses' => [
                // Kit Request Phase (Online Only)
                ['name' => 'Pending Kit Request', 'slug' => 'pending_kit_request', 'color' => '#f59e0b', 'behavior' => ['allows_cancellation' => true]],
                ['name' => 'Kit Request Confirmed', 'slug' => 'kit_request_confirmed', 'color' => '#22c55e', 'behavior' => ['allows_cancellation' => true]],
                ['name' => 'Kit Request Rejected', 'slug' => 'kit_request_rejected', 'color' => '#ef4444', 'is_final' => true],
                ['name' => 'Kit Request On Hold', 'slug' => 'kit_request_on_hold', 'color' => '#6b7280', 'behavior' => ['allows_cancellation' => true]],
                // Kit Shipping Phase
                ['name' => 'Kit Sent', 'slug' => 'kit_sent', 'color' => '#3b82f6', 'behavior' => ['allows_cancellation' => true]],
                ['name' => 'Kit Delivered', 'slug' => 'kit_delivered', 'color' => '#6366f1'],
                // Items Phase
                ['name' => 'Pending', 'slug' => 'pending', 'color' => '#f59e0b', 'is_default' => true, 'behavior' => ['allows_cancellation' => true]],
                ['name' => 'Items Received', 'slug' => 'items_received', 'color' => '#3b82f6'],
                ['name' => 'Items Reviewed', 'slug' => 'items_reviewed', 'color' => '#6366f1'],
                // Offer Phase
                ['name' => 'Offer Given', 'slug' => 'offer_given', 'color' => '#8b5cf6', 'behavior' => ['allows_offer' => true, 'allows_cancellation' => true]],
                ['name' => 'Offer Accepted', 'slug' => 'offer_accepted', 'color' => '#10b981', 'behavior' => ['allows_payment' => true]],
                ['name' => 'Offer Declined', 'slug' => 'offer_declined', 'color' => '#ef4444', 'behavior' => ['allows_offer' => true, 'allows_cancellation' => true]],
                // Payment Phase
                ['name' => 'Payment Pending', 'slug' => 'payment_pending', 'color' => '#f97316', 'behavior' => ['allows_payment' => true]],
                ['name' => 'Payment Processed', 'slug' => 'payment_processed', 'color' => '#22c55e', 'is_final' => true],
                // Return/Cancellation
                ['name' => 'Return Requested', 'slug' => 'return_requested', 'color' => '#f97316'],
                ['name' => 'Items Returned', 'slug' => 'items_returned', 'color' => '#6b7280', 'is_final' => true],
                ['name' => 'Cancelled', 'slug' => 'cancelled', 'color' => '#6b7280', 'is_final' => true],
            ],
            'transitions' => [
                // Kit Request flow
                ['from' => 'pending_kit_request', 'to' => 'kit_request_confirmed', 'name' => 'Confirm Kit Request'],
                ['from' => 'pending_kit_request', 'to' => 'kit_request_rejected', 'name' => 'Reject Kit Request'],
                ['from' => 'pending_kit_request', 'to' => 'kit_request_on_hold', 'name' => 'Put On Hold'],
                ['from' => 'kit_request_on_hold', 'to' => 'kit_request_confirmed', 'name' => 'Confirm Kit Request'],
                ['from' => 'kit_request_confirmed', 'to' => 'kit_sent', 'name' => 'Mark Kit Sent'],
                ['from' => 'kit_sent', 'to' => 'kit_delivered', 'name' => 'Mark Kit Delivered'],
                ['from' => 'kit_delivered', 'to' => 'items_received', 'name' => 'Mark Items Received'],
                // In-house flow
                ['from' => 'pending', 'to' => 'items_received', 'name' => 'Mark Items Received'],
                ['from' => 'pending', 'to' => 'offer_given', 'name' => 'Submit Offer'],
                ['from' => 'pending', 'to' => 'cancelled', 'name' => 'Cancel'],
                // Items flow
                ['from' => 'items_received', 'to' => 'items_reviewed', 'name' => 'Mark Items Reviewed'],
                ['from' => 'items_reviewed', 'to' => 'offer_given', 'name' => 'Submit Offer'],
                // Offer flow
                ['from' => 'offer_given', 'to' => 'offer_accepted', 'name' => 'Accept Offer'],
                ['from' => 'offer_given', 'to' => 'offer_declined', 'name' => 'Decline Offer'],
                ['from' => 'offer_given', 'to' => 'cancelled', 'name' => 'Cancel'],
                ['from' => 'offer_declined', 'to' => 'offer_given', 'name' => 'Submit Counter Offer'],
                ['from' => 'offer_declined', 'to' => 'cancelled', 'name' => 'Cancel'],
                ['from' => 'offer_declined', 'to' => 'return_requested', 'name' => 'Request Return'],
                // Payment flow
                ['from' => 'offer_accepted', 'to' => 'payment_processed', 'name' => 'Process Payment'],
                ['from' => 'offer_accepted', 'to' => 'payment_pending', 'name' => 'Mark Payment Pending'],
                ['from' => 'payment_pending', 'to' => 'payment_processed', 'name' => 'Process Payment'],
                // Return flow
                ['from' => 'return_requested', 'to' => 'items_returned', 'name' => 'Mark Items Returned'],
            ],
        ];
    }

    /**
     * Get order status definitions.
     */
    protected function getOrderStatusDefinitions(): array
    {
        return [
            'statuses' => [
                ['name' => 'Draft', 'slug' => 'draft', 'color' => '#9ca3af', 'is_default' => true, 'behavior' => ['allows_editing' => true, 'allows_cancellation' => true]],
                ['name' => 'Pending', 'slug' => 'pending', 'color' => '#f59e0b', 'behavior' => ['allows_payment' => true, 'allows_cancellation' => true]],
                ['name' => 'Confirmed', 'slug' => 'confirmed', 'color' => '#3b82f6'],
                ['name' => 'Processing', 'slug' => 'processing', 'color' => '#6366f1'],
                ['name' => 'Shipped', 'slug' => 'shipped', 'color' => '#8b5cf6'],
                ['name' => 'Delivered', 'slug' => 'delivered', 'color' => '#10b981'],
                ['name' => 'Completed', 'slug' => 'completed', 'color' => '#22c55e', 'is_final' => true],
                ['name' => 'Cancelled', 'slug' => 'cancelled', 'color' => '#6b7280', 'is_final' => true],
                ['name' => 'Refunded', 'slug' => 'refunded', 'color' => '#ef4444', 'is_final' => true],
                ['name' => 'Partial Payment', 'slug' => 'partial_payment', 'color' => '#f97316', 'behavior' => ['allows_payment' => true]],
            ],
            'transitions' => [
                ['from' => 'draft', 'to' => 'pending', 'name' => 'Submit Order'],
                ['from' => 'draft', 'to' => 'cancelled', 'name' => 'Cancel'],
                ['from' => 'pending', 'to' => 'confirmed', 'name' => 'Confirm Order'],
                ['from' => 'pending', 'to' => 'partial_payment', 'name' => 'Record Partial Payment'],
                ['from' => 'pending', 'to' => 'cancelled', 'name' => 'Cancel'],
                ['from' => 'partial_payment', 'to' => 'confirmed', 'name' => 'Confirm Order'],
                ['from' => 'partial_payment', 'to' => 'cancelled', 'name' => 'Cancel'],
                ['from' => 'confirmed', 'to' => 'processing', 'name' => 'Start Processing'],
                ['from' => 'confirmed', 'to' => 'shipped', 'name' => 'Mark Shipped'],
                ['from' => 'processing', 'to' => 'shipped', 'name' => 'Mark Shipped'],
                ['from' => 'shipped', 'to' => 'delivered', 'name' => 'Mark Delivered'],
                ['from' => 'delivered', 'to' => 'completed', 'name' => 'Complete Order'],
                ['from' => 'confirmed', 'to' => 'refunded', 'name' => 'Refund'],
                ['from' => 'processing', 'to' => 'refunded', 'name' => 'Refund'],
                ['from' => 'shipped', 'to' => 'refunded', 'name' => 'Refund'],
                ['from' => 'delivered', 'to' => 'refunded', 'name' => 'Refund'],
            ],
        ];
    }

    /**
     * Get repair status definitions.
     */
    protected function getRepairStatusDefinitions(): array
    {
        return [
            'statuses' => [
                ['name' => 'Pending', 'slug' => 'pending', 'color' => '#f59e0b', 'is_default' => true, 'behavior' => ['allows_editing' => true, 'allows_cancellation' => true]],
                ['name' => 'Sent to Vendor', 'slug' => 'sent_to_vendor', 'color' => '#3b82f6', 'behavior' => ['allows_cancellation' => true]],
                ['name' => 'Received by Vendor', 'slug' => 'received_by_vendor', 'color' => '#6366f1', 'behavior' => ['allows_cancellation' => true]],
                ['name' => 'Completed', 'slug' => 'completed', 'color' => '#22c55e', 'behavior' => ['allows_payment' => true]],
                ['name' => 'Payment Received', 'slug' => 'payment_received', 'color' => '#10b981', 'is_final' => true],
                ['name' => 'Refunded', 'slug' => 'refunded', 'color' => '#ef4444', 'is_final' => true],
                ['name' => 'Cancelled', 'slug' => 'cancelled', 'color' => '#6b7280', 'is_final' => true],
                ['name' => 'Archived', 'slug' => 'archived', 'color' => '#9ca3af', 'is_final' => true],
            ],
            'transitions' => [
                ['from' => 'pending', 'to' => 'sent_to_vendor', 'name' => 'Send to Vendor'],
                ['from' => 'pending', 'to' => 'cancelled', 'name' => 'Cancel'],
                ['from' => 'sent_to_vendor', 'to' => 'received_by_vendor', 'name' => 'Mark Received by Vendor'],
                ['from' => 'sent_to_vendor', 'to' => 'cancelled', 'name' => 'Cancel'],
                ['from' => 'received_by_vendor', 'to' => 'completed', 'name' => 'Mark Completed'],
                ['from' => 'received_by_vendor', 'to' => 'cancelled', 'name' => 'Cancel'],
                ['from' => 'completed', 'to' => 'payment_received', 'name' => 'Record Payment'],
                ['from' => 'completed', 'to' => 'refunded', 'name' => 'Refund'],
                ['from' => 'payment_received', 'to' => 'archived', 'name' => 'Archive'],
                ['from' => 'payment_received', 'to' => 'refunded', 'name' => 'Refund'],
            ],
        ];
    }

    /**
     * Get memo status definitions.
     */
    protected function getMemoStatusDefinitions(): array
    {
        return [
            'statuses' => [
                ['name' => 'Pending', 'slug' => 'pending', 'color' => '#f59e0b', 'is_default' => true, 'behavior' => ['allows_editing' => true, 'allows_cancellation' => true]],
                ['name' => 'Sent to Vendor', 'slug' => 'sent_to_vendor', 'color' => '#3b82f6', 'behavior' => ['allows_cancellation' => true]],
                ['name' => 'Vendor Received', 'slug' => 'vendor_received', 'color' => '#6366f1', 'behavior' => ['allows_payment' => true]],
                ['name' => 'Vendor Returned', 'slug' => 'vendor_returned', 'color' => '#8b5cf6', 'is_final' => true],
                ['name' => 'Payment Received', 'slug' => 'payment_received', 'color' => '#22c55e', 'is_final' => true],
                ['name' => 'Archived', 'slug' => 'archived', 'color' => '#9ca3af', 'is_final' => true],
                ['name' => 'Cancelled', 'slug' => 'cancelled', 'color' => '#6b7280', 'is_final' => true],
            ],
            'transitions' => [
                ['from' => 'pending', 'to' => 'sent_to_vendor', 'name' => 'Send to Vendor'],
                ['from' => 'pending', 'to' => 'cancelled', 'name' => 'Cancel'],
                ['from' => 'sent_to_vendor', 'to' => 'vendor_received', 'name' => 'Mark Vendor Received'],
                ['from' => 'sent_to_vendor', 'to' => 'cancelled', 'name' => 'Cancel'],
                ['from' => 'vendor_received', 'to' => 'vendor_returned', 'name' => 'Mark Vendor Returned'],
                ['from' => 'vendor_received', 'to' => 'payment_received', 'name' => 'Record Payment'],
                ['from' => 'vendor_returned', 'to' => 'archived', 'name' => 'Archive'],
                ['from' => 'payment_received', 'to' => 'archived', 'name' => 'Archive'],
            ],
        ];
    }

    /**
     * Reorder statuses for a store and entity type.
     *
     * @param  array<int>  $statusIds  Ordered list of status IDs
     */
    public function reorderStatuses(array $statusIds): void
    {
        foreach ($statusIds as $index => $statusId) {
            Status::where('id', $statusId)->update(['sort_order' => $index]);
        }
    }
}
