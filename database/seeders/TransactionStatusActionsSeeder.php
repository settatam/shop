<?php

namespace Database\Seeders;

use App\Models\Status;
use App\Models\StatusAction;
use App\Models\Store;
use Illuminate\Database\Seeder;

class TransactionStatusActionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all stores and seed actions for each
        Store::all()->each(function (Store $store) {
            $this->seedActionsForStore($store->id);
        });
    }

    protected function seedActionsForStore(int $storeId): void
    {
        // Get all transaction statuses for this store
        $statuses = Status::where('store_id', $storeId)
            ->where('entity_type', 'transaction')
            ->get()
            ->keyBy('slug');

        if ($statuses->isEmpty()) {
            return;
        }

        // Define actions for each status
        $actionDefinitions = $this->getActionDefinitions();

        foreach ($actionDefinitions as $statusSlug => $actions) {
            $status = $statuses->get($statusSlug);
            if (! $status) {
                continue;
            }

            // Skip if actions already exist for this status
            if ($status->actions()->exists()) {
                continue;
            }

            foreach ($actions as $index => $action) {
                $config = $action['config'] ?? [];

                // Resolve target_status_slug to target_status_id
                if (isset($config['target_status_slug'])) {
                    $targetStatus = $statuses->get($config['target_status_slug']);
                    if ($targetStatus) {
                        $config['target_status_id'] = $targetStatus->id;
                        $config['target_status_name'] = $targetStatus->name;
                    }
                    unset($config['target_status_slug']);
                }

                StatusAction::create([
                    'status_id' => $status->id,
                    'action_type' => $action['type'],
                    'name' => $action['name'],
                    'icon' => $action['icon'] ?? null,
                    'color' => $action['color'] ?? null,
                    'config' => $config,
                    'is_bulk' => $action['is_bulk'] ?? true,
                    'requires_confirmation' => $action['requires_confirmation'] ?? false,
                    'confirmation_message' => $action['confirmation_message'] ?? null,
                    'sort_order' => $index,
                    'is_enabled' => true,
                ]);
            }
        }
    }

    /**
     * Get action definitions for each status.
     *
     * @return array<string, array<array<string, mixed>>>
     */
    protected function getActionDefinitions(): array
    {
        return [
            // Kit Request Phase
            'pending_kit_request' => [
                ['type' => StatusAction::TYPE_PRINT_SHIPPING_LABEL, 'name' => 'Print Shipping Labels', 'icon' => 'truck'],
                ['type' => StatusAction::TYPE_PRINT_BARCODE, 'name' => 'Print Barcodes', 'icon' => 'qr-code'],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Confirm Kit Request', 'icon' => 'check', 'color' => 'green', 'config' => ['target_status_slug' => 'kit_request_confirmed']],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Put On Hold', 'icon' => 'pause', 'color' => 'yellow', 'config' => ['target_status_slug' => 'kit_request_on_hold']],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Reject Kit Request', 'icon' => 'x-mark', 'color' => 'red', 'config' => ['target_status_slug' => 'kit_request_rejected'], 'requires_confirmation' => true, 'confirmation_message' => 'Are you sure you want to reject these kit requests?'],
                ['type' => StatusAction::TYPE_DELETE, 'name' => 'Delete', 'icon' => 'trash', 'color' => 'red', 'requires_confirmation' => true, 'confirmation_message' => 'Are you sure you want to delete these transactions?'],
            ],

            'kit_request_confirmed' => [
                ['type' => StatusAction::TYPE_PRINT_SHIPPING_LABEL, 'name' => 'Print Shipping Labels', 'icon' => 'truck'],
                ['type' => StatusAction::TYPE_PRINT_BARCODE, 'name' => 'Print Barcodes', 'icon' => 'qr-code'],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Mark Kit Sent', 'icon' => 'paper-airplane', 'color' => 'blue', 'config' => ['target_status_slug' => 'kit_sent']],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Put On Hold', 'icon' => 'pause', 'color' => 'yellow', 'config' => ['target_status_slug' => 'kit_request_on_hold']],
            ],

            'kit_request_on_hold' => [
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Confirm Kit Request', 'icon' => 'check', 'color' => 'green', 'config' => ['target_status_slug' => 'kit_request_confirmed']],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Reject Kit Request', 'icon' => 'x-mark', 'color' => 'red', 'config' => ['target_status_slug' => 'kit_request_rejected'], 'requires_confirmation' => true, 'confirmation_message' => 'Are you sure you want to reject these kit requests?'],
                ['type' => StatusAction::TYPE_DELETE, 'name' => 'Delete', 'icon' => 'trash', 'color' => 'red', 'requires_confirmation' => true, 'confirmation_message' => 'Are you sure you want to delete these transactions?'],
            ],

            'kit_request_rejected' => [
                ['type' => StatusAction::TYPE_EXPORT, 'name' => 'Export', 'icon' => 'arrow-down-tray'],
                ['type' => StatusAction::TYPE_DELETE, 'name' => 'Delete', 'icon' => 'trash', 'color' => 'red', 'requires_confirmation' => true, 'confirmation_message' => 'Are you sure you want to delete these transactions?'],
            ],

            // Kit Shipping Phase
            'kit_sent' => [
                ['type' => StatusAction::TYPE_PRINT_SHIPPING_LABEL, 'name' => 'Reprint Shipping Labels', 'icon' => 'truck'],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Mark Kit Delivered', 'icon' => 'check-circle', 'color' => 'green', 'config' => ['target_status_slug' => 'kit_delivered']],
            ],

            'kit_delivered' => [
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Mark Items Received', 'icon' => 'inbox-arrow-down', 'color' => 'blue', 'config' => ['target_status_slug' => 'items_received']],
            ],

            // In-house Items Phase
            'pending' => [
                ['type' => StatusAction::TYPE_PRINT_BARCODE, 'name' => 'Print Barcodes', 'icon' => 'qr-code'],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Mark Items Received', 'icon' => 'inbox-arrow-down', 'color' => 'blue', 'config' => ['target_status_slug' => 'items_received']],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Submit Offer', 'icon' => 'currency-dollar', 'color' => 'purple', 'config' => ['target_status_slug' => 'offer_given']],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Cancel', 'icon' => 'x-mark', 'color' => 'red', 'config' => ['target_status_slug' => 'cancelled'], 'requires_confirmation' => true, 'confirmation_message' => 'Are you sure you want to cancel these transactions?'],
                ['type' => StatusAction::TYPE_DELETE, 'name' => 'Delete', 'icon' => 'trash', 'color' => 'red', 'requires_confirmation' => true, 'confirmation_message' => 'Are you sure you want to delete these transactions?'],
            ],

            'items_received' => [
                ['type' => StatusAction::TYPE_PRINT_BARCODE, 'name' => 'Print Barcodes', 'icon' => 'qr-code'],
                ['type' => StatusAction::TYPE_PRINT_RETURN_LABEL, 'name' => 'Print Return Labels', 'icon' => 'arrow-uturn-left'],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Mark Items Reviewed', 'icon' => 'clipboard-document-check', 'color' => 'indigo', 'config' => ['target_status_slug' => 'items_reviewed']],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Cancel', 'icon' => 'x-mark', 'color' => 'red', 'config' => ['target_status_slug' => 'cancelled'], 'requires_confirmation' => true, 'confirmation_message' => 'Are you sure you want to cancel these transactions?'],
            ],

            'items_reviewed' => [
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Submit Offer', 'icon' => 'currency-dollar', 'color' => 'purple', 'config' => ['target_status_slug' => 'offer_given']],
            ],

            // Offer Phase
            'offer_given' => [
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Accept Offer', 'icon' => 'check', 'color' => 'green', 'config' => ['target_status_slug' => 'offer_accepted']],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Decline Offer', 'icon' => 'x-mark', 'color' => 'red', 'config' => ['target_status_slug' => 'offer_declined']],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Cancel', 'icon' => 'ban', 'color' => 'gray', 'config' => ['target_status_slug' => 'cancelled'], 'requires_confirmation' => true, 'confirmation_message' => 'Are you sure you want to cancel these transactions?'],
            ],

            'offer_accepted' => [
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Process Payment', 'icon' => 'banknotes', 'color' => 'green', 'config' => ['target_status_slug' => 'payment_processed']],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Mark Payment Pending', 'icon' => 'clock', 'color' => 'yellow', 'config' => ['target_status_slug' => 'payment_pending']],
            ],

            'offer_declined' => [
                ['type' => StatusAction::TYPE_PRINT_RETURN_LABEL, 'name' => 'Print Return Labels', 'icon' => 'arrow-uturn-left'],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Submit Counter Offer', 'icon' => 'currency-dollar', 'color' => 'purple', 'config' => ['target_status_slug' => 'offer_given']],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Request Return', 'icon' => 'arrow-uturn-left', 'color' => 'orange', 'config' => ['target_status_slug' => 'return_requested']],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Cancel', 'icon' => 'ban', 'color' => 'gray', 'config' => ['target_status_slug' => 'cancelled'], 'requires_confirmation' => true, 'confirmation_message' => 'Are you sure you want to cancel these transactions?'],
            ],

            // Payment Phase
            'payment_pending' => [
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Process Payment', 'icon' => 'banknotes', 'color' => 'green', 'config' => ['target_status_slug' => 'payment_processed']],
            ],

            'payment_processed' => [
                ['type' => StatusAction::TYPE_EXPORT, 'name' => 'Export', 'icon' => 'arrow-down-tray'],
            ],

            // Return Phase
            'return_requested' => [
                ['type' => StatusAction::TYPE_PRINT_RETURN_LABEL, 'name' => 'Print Return Labels', 'icon' => 'arrow-uturn-left'],
                ['type' => StatusAction::TYPE_CHANGE_STATUS, 'name' => 'Mark Items Returned', 'icon' => 'check', 'color' => 'green', 'config' => ['target_status_slug' => 'items_returned']],
            ],

            'items_returned' => [
                ['type' => StatusAction::TYPE_EXPORT, 'name' => 'Export', 'icon' => 'arrow-down-tray'],
            ],

            'cancelled' => [
                ['type' => StatusAction::TYPE_EXPORT, 'name' => 'Export', 'icon' => 'arrow-down-tray'],
                ['type' => StatusAction::TYPE_DELETE, 'name' => 'Delete', 'icon' => 'trash', 'color' => 'red', 'requires_confirmation' => true, 'confirmation_message' => 'Are you sure you want to permanently delete these transactions?'],
            ],
        ];
    }
}
