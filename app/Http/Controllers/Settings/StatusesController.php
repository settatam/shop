<?php

namespace App\Http\Controllers\Settings;

use App\Enums\StatusableType;
use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Models\Status;
use App\Models\StatusAutomation;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatusesController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    /**
     * Display the statuses management page.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $statuses = Status::where('store_id', $store->id)
            ->with(['outgoingTransitions.toStatus', 'automations'])
            ->orderBy('entity_type')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Status $status) => [
                'id' => $status->id,
                'name' => $status->name,
                'slug' => $status->slug,
                'entity_type' => $status->entity_type,
                'color' => $status->color,
                'icon' => $status->icon,
                'description' => $status->description,
                'is_default' => $status->is_default,
                'is_final' => $status->is_final,
                'is_system' => $status->is_system,
                'sort_order' => $status->sort_order,
                'behavior' => $status->behavior ?? [],
                'transitions' => $status->outgoingTransitions->map(fn ($t) => [
                    'id' => $t->id,
                    'to_status_id' => $t->to_status_id,
                    'to_status_name' => $t->toStatus->name,
                    'to_status_slug' => $t->toStatus->slug,
                    'name' => $t->name,
                    'is_enabled' => $t->is_enabled,
                ])->values(),
                'automations' => $status->automations->map(fn ($a) => [
                    'id' => $a->id,
                    'trigger' => $a->trigger,
                    'action_type' => $a->action_type,
                    'action_config' => $a->action_config,
                    'is_enabled' => $a->is_enabled,
                    'sort_order' => $a->sort_order,
                ])->values(),
                'automations_count' => $status->automations->count(),
            ])
            ->groupBy('entity_type');

        $entityTypes = collect(StatusableType::cases())->map(fn ($type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'plural_label' => $type->pluralLabel(),
        ]);

        $behaviorFlags = [
            'allows_payment' => 'Allows payment processing',
            'allows_cancellation' => 'Allows cancellation',
            'allows_editing' => 'Allows editing',
            'allows_items_modification' => 'Allows adding/removing items',
            'requires_payment' => 'Requires payment to transition',
            'notifies_customer' => 'Notifies customer on entry',
        ];

        $notificationTemplates = NotificationTemplate::where('store_id', $store->id)
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get(['id', 'name', 'channel']);

        $automationOptions = [
            'triggers' => [
                ['value' => StatusAutomation::TRIGGER_ON_ENTER, 'label' => 'When entering this status'],
                ['value' => StatusAutomation::TRIGGER_ON_EXIT, 'label' => 'When leaving this status'],
            ],
            'action_types' => [
                ['value' => StatusAutomation::ACTION_NOTIFICATION, 'label' => 'Send notification'],
                ['value' => StatusAutomation::ACTION_WEBHOOK, 'label' => 'Call webhook'],
                ['value' => StatusAutomation::ACTION_CUSTOM, 'label' => 'Run custom action'],
            ],
            'recipients' => [
                ['value' => 'owner', 'label' => 'Store owner'],
                ['value' => 'customer', 'label' => 'Customer'],
                ['value' => 'vendor', 'label' => 'Vendor'],
                ['value' => 'assigned_user', 'label' => 'Assigned user'],
            ],
            'custom_actions' => [
                ['value' => 'mark_paid', 'label' => 'Mark as paid'],
                ['value' => 'send_email', 'label' => 'Send custom email'],
                ['value' => 'update_inventory', 'label' => 'Update inventory'],
                ['value' => 'create_invoice', 'label' => 'Create invoice'],
            ],
            'webhook_methods' => [
                ['value' => 'POST', 'label' => 'POST'],
                ['value' => 'GET', 'label' => 'GET'],
                ['value' => 'PUT', 'label' => 'PUT'],
                ['value' => 'PATCH', 'label' => 'PATCH'],
            ],
        ];

        return Inertia::render('settings/Statuses', [
            'statuses' => $statuses,
            'entityTypes' => $entityTypes,
            'behaviorFlags' => $behaviorFlags,
            'notificationTemplates' => $notificationTemplates,
            'automationOptions' => $automationOptions,
        ]);
    }
}
