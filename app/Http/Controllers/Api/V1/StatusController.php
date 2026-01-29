<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusableType;
use App\Http\Controllers\Controller;
use App\Models\Status;
use App\Models\StatusAutomation;
use App\Models\StatusTransition;
use App\Services\Statuses\StatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StatusController extends Controller
{
    public function __construct(
        protected StatusService $statusService
    ) {}

    /**
     * List statuses for the current store.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => ['nullable', 'string', Rule::in(array_column(StatusableType::cases(), 'value'))],
        ]);

        $query = Status::query()
            ->withCount(['outgoingTransitions', 'automations'])
            ->orderBy('entity_type')
            ->orderBy('sort_order');

        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        $statuses = $query->get();

        return response()->json([
            'statuses' => $statuses,
            'entity_types' => collect(StatusableType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
        ]);
    }

    /**
     * Create a new status.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(array_column(StatusableType::cases(), 'value'))],
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('statuses')->where(function ($query) use ($request) {
                    return $query
                        ->where('store_id', $request->user()->currentStore()?->id)
                        ->where('entity_type', $request->input('entity_type'));
                }),
            ],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
            'is_final' => ['nullable', 'boolean'],
            'behavior' => ['nullable', 'array'],
        ]);

        $maxOrder = Status::query()
            ->where('entity_type', $validated['entity_type'])
            ->max('sort_order') ?? -1;

        $status = Status::create([
            'entity_type' => $validated['entity_type'],
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'color' => $validated['color'] ?? '#6b7280',
            'icon' => $validated['icon'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_default' => $validated['is_default'] ?? false,
            'is_final' => $validated['is_final'] ?? false,
            'is_system' => false,
            'sort_order' => $maxOrder + 1,
            'behavior' => $validated['behavior'] ?? [],
        ]);

        return response()->json($status, 201);
    }

    /**
     * Show a specific status.
     */
    public function show(Status $status): JsonResponse
    {
        $status->load(['outgoingTransitions.toStatus', 'incomingTransitions.fromStatus', 'automations']);

        return response()->json($status);
    }

    /**
     * Update a status.
     */
    public function update(Request $request, Status $status): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
            'is_final' => ['nullable', 'boolean'],
            'behavior' => ['nullable', 'array'],
        ]);

        // Don't allow changing system status settings except certain fields
        if ($status->is_system) {
            unset($validated['is_default'], $validated['is_final']);
        }

        $status->update($validated);

        return response()->json($status);
    }

    /**
     * Delete a status.
     */
    public function destroy(Status $status): JsonResponse
    {
        if ($status->is_system) {
            return response()->json(['message' => 'Cannot delete system statuses'], 403);
        }

        if ($status->is_default) {
            return response()->json(['message' => 'Cannot delete the default status. Set another status as default first.'], 422);
        }

        // Check if any entities are using this status
        // This would require checking each entity type
        $status->delete();

        return response()->json(null, 204);
    }

    /**
     * Reorder statuses.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status_ids' => ['required', 'array'],
            'status_ids.*' => ['integer', 'exists:statuses,id'],
        ]);

        $this->statusService->reorderStatuses($validated['status_ids']);

        return response()->json(['message' => 'Statuses reordered successfully']);
    }

    /**
     * List transitions for a status.
     */
    public function transitions(Status $status): JsonResponse
    {
        $transitions = $status->outgoingTransitions()
            ->with('toStatus')
            ->get();

        return response()->json($transitions);
    }

    /**
     * Create a transition from this status.
     */
    public function storeTransition(Request $request, Status $status): JsonResponse
    {
        $validated = $request->validate([
            'to_status_id' => [
                'required',
                'integer',
                'exists:statuses,id',
                Rule::unique('status_transitions', 'to_status_id')
                    ->where('from_status_id', $status->id),
            ],
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'conditions' => ['nullable', 'array'],
            'required_fields' => ['nullable', 'array'],
        ]);

        // Verify target status is for the same entity type
        $toStatus = Status::find($validated['to_status_id']);
        if ($toStatus->entity_type !== $status->entity_type) {
            return response()->json(['message' => 'Target status must be of the same entity type'], 422);
        }

        $transition = StatusTransition::create([
            'from_status_id' => $status->id,
            'to_status_id' => $validated['to_status_id'],
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'conditions' => $validated['conditions'] ?? null,
            'required_fields' => $validated['required_fields'] ?? null,
            'is_enabled' => true,
        ]);

        $transition->load('toStatus');

        return response()->json($transition, 201);
    }

    /**
     * Update a transition.
     */
    public function updateTransition(Request $request, StatusTransition $transition): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'conditions' => ['nullable', 'array'],
            'required_fields' => ['nullable', 'array'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $transition->update($validated);

        return response()->json($transition);
    }

    /**
     * Delete a transition.
     */
    public function destroyTransition(StatusTransition $transition): JsonResponse
    {
        $transition->delete();

        return response()->json(null, 204);
    }

    /**
     * List automations for a status.
     */
    public function automations(Status $status): JsonResponse
    {
        $automations = $status->automations()->orderBy('sort_order')->get();

        return response()->json($automations);
    }

    /**
     * Create an automation for a status.
     */
    public function storeAutomation(Request $request, Status $status): JsonResponse
    {
        $validated = $request->validate([
            'trigger' => ['required', 'string', Rule::in(['on_enter', 'on_exit'])],
            'action_type' => ['required', 'string', Rule::in(['notification', 'webhook', 'custom'])],
            'action_config' => ['required', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Validate action_config based on action_type
        $this->validateActionConfig($validated['action_type'], $validated['action_config']);

        $maxOrder = $status->automations()->max('sort_order') ?? -1;

        $automation = StatusAutomation::create([
            'status_id' => $status->id,
            'trigger' => $validated['trigger'],
            'action_type' => $validated['action_type'],
            'action_config' => $validated['action_config'],
            'sort_order' => $validated['sort_order'] ?? ($maxOrder + 1),
            'is_enabled' => true,
        ]);

        return response()->json($automation, 201);
    }

    /**
     * Update an automation.
     */
    public function updateAutomation(Request $request, StatusAutomation $automation): JsonResponse
    {
        $validated = $request->validate([
            'trigger' => ['sometimes', 'string', Rule::in(['on_enter', 'on_exit'])],
            'action_type' => ['sometimes', 'string', Rule::in(['notification', 'webhook', 'custom'])],
            'action_config' => ['sometimes', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        // Validate action_config if provided
        if (isset($validated['action_config'])) {
            $actionType = $validated['action_type'] ?? $automation->action_type;
            $this->validateActionConfig($actionType, $validated['action_config']);
        }

        $automation->update($validated);

        return response()->json($automation);
    }

    /**
     * Delete an automation.
     */
    public function destroyAutomation(StatusAutomation $automation): JsonResponse
    {
        $automation->delete();

        return response()->json(null, 204);
    }

    /**
     * Get available transitions for an entity.
     */
    public function availableTransitions(Request $request, string $entityType, int $entityId): JsonResponse
    {
        $type = StatusableType::tryFrom($entityType);

        if (! $type) {
            return response()->json(['message' => 'Invalid entity type'], 404);
        }

        $modelClass = $type->modelClass();
        $entity = $modelClass::find($entityId);

        if (! $entity) {
            return response()->json(['message' => 'Entity not found'], 404);
        }

        $transitions = $entity->getAvailableTransitions();

        return response()->json([
            'current_status' => $entity->statusModel,
            'available_transitions' => $transitions,
        ]);
    }

    /**
     * Transition an entity to a new status.
     */
    public function transitionEntity(Request $request, string $entityType, int $entityId): JsonResponse
    {
        $type = StatusableType::tryFrom($entityType);

        if (! $type) {
            return response()->json(['message' => 'Invalid entity type'], 404);
        }

        $validated = $request->validate([
            'status_id' => ['required', 'integer', 'exists:statuses,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $modelClass = $type->modelClass();
        $entity = $modelClass::find($entityId);

        if (! $entity) {
            return response()->json(['message' => 'Entity not found'], 404);
        }

        $targetStatus = Status::find($validated['status_id']);

        if (! $entity->canTransitionTo($targetStatus)) {
            return response()->json(['message' => 'This transition is not allowed'], 422);
        }

        $success = $entity->transitionTo($targetStatus, ['notes' => $validated['notes'] ?? null]);

        if (! $success) {
            return response()->json(['message' => 'Failed to transition status'], 500);
        }

        $entity->refresh();

        return response()->json([
            'message' => 'Status updated successfully',
            'entity' => $entity,
            'status' => $entity->statusModel,
        ]);
    }

    /**
     * Validate action config based on action type.
     *
     * @param  array<string, mixed>  $config
     */
    protected function validateActionConfig(string $actionType, array $config): void
    {
        match ($actionType) {
            'notification' => $this->validateNotificationConfig($config),
            'webhook' => $this->validateWebhookConfig($config),
            'custom' => $this->validateCustomConfig($config),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function validateNotificationConfig(array $config): void
    {
        if (empty($config['template_id'])) {
            abort(422, 'Notification automation requires a template_id');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function validateWebhookConfig(array $config): void
    {
        if (empty($config['url'])) {
            abort(422, 'Webhook automation requires a url');
        }

        if (! filter_var($config['url'], FILTER_VALIDATE_URL)) {
            abort(422, 'Webhook URL must be a valid URL');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function validateCustomConfig(array $config): void
    {
        if (empty($config['action'])) {
            abort(422, 'Custom automation requires an action');
        }
    }
}
