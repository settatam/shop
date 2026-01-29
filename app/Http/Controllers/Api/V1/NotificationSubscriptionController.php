<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\NotificationLog;
use App\Models\NotificationSubscription;
use App\Services\Notifications\NotificationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = NotificationSubscription::query()
            ->with('template:id,name,channel,category');

        if ($request->has('activity')) {
            $query->forActivity($request->input('activity'));
        }

        if ($request->has('enabled')) {
            $query->where('is_enabled', $request->boolean('enabled'));
        }

        $subscriptions = $request->boolean('all')
            ? $query->orderBy('activity')->get()
            : $query->orderBy('activity')->paginate($request->input('per_page', 15));

        return response()->json($subscriptions);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $request->user()->currentStore()?->id;

        $validated = $request->validate([
            'notification_template_id' => [
                'required',
                'exists:notification_templates,id',
                Rule::exists('notification_templates', 'id')->where('store_id', $storeId),
            ],
            'activity' => ['required', 'string', Rule::in(Activity::getAllSlugs())],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'conditions' => ['nullable', 'array'],
            'conditions.*.field' => ['required_with:conditions', 'string'],
            'conditions.*.operator' => ['required_with:conditions', 'string'],
            'conditions.*.value' => ['required_with:conditions'],
            'recipients' => ['nullable', 'array'],
            'schedule_type' => ['nullable', 'string', Rule::in(['immediate', 'delayed', 'scheduled'])],
            'delay_minutes' => ['nullable', 'integer', 'min:1'],
            'delay_unit' => ['nullable', 'string', Rule::in(['minutes', 'hours', 'days'])],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $subscription = NotificationSubscription::create([
            ...$validated,
            'store_id' => $storeId,
            'schedule_type' => $validated['schedule_type'] ?? 'immediate',
            'is_enabled' => $validated['is_enabled'] ?? true,
        ]);

        $subscription->load('template:id,name,channel,category');

        return response()->json($subscription, 201);
    }

    public function show(NotificationSubscription $notificationSubscription): JsonResponse
    {
        $notificationSubscription->load(['template', 'logs' => fn ($q) => $q->latest()->limit(10)]);

        return response()->json($notificationSubscription);
    }

    public function update(Request $request, NotificationSubscription $notificationSubscription): JsonResponse
    {
        $storeId = $request->user()->currentStore()?->id;

        $validated = $request->validate([
            'notification_template_id' => [
                'sometimes',
                'exists:notification_templates,id',
                Rule::exists('notification_templates', 'id')->where('store_id', $storeId),
            ],
            'activity' => ['sometimes', 'string', Rule::in(Activity::getAllSlugs())],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'conditions' => ['nullable', 'array'],
            'recipients' => ['nullable', 'array'],
            'schedule_type' => ['nullable', 'string', Rule::in(['immediate', 'delayed', 'scheduled'])],
            'delay_minutes' => ['nullable', 'integer', 'min:1'],
            'delay_unit' => ['nullable', 'string', Rule::in(['minutes', 'hours', 'days'])],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $notificationSubscription->update($validated);
        $notificationSubscription->load('template:id,name,channel,category');

        return response()->json($notificationSubscription);
    }

    public function destroy(NotificationSubscription $notificationSubscription): JsonResponse
    {
        $notificationSubscription->delete();

        return response()->json(null, 204);
    }

    /**
     * Get available activities that can be subscribed to.
     */
    public function activities(): JsonResponse
    {
        $activities = Activity::getDefinitions();

        // Group by category
        $grouped = collect($activities)->groupBy('category');

        return response()->json([
            'activities' => $activities,
            'grouped' => $grouped,
            'categories' => $grouped->keys()->toArray(),
        ]);
    }

    /**
     * Test trigger a subscription manually.
     */
    public function test(Request $request, NotificationSubscription $notificationSubscription): JsonResponse
    {
        $validated = $request->validate([
            'data' => ['nullable', 'array'],
            'recipient' => ['nullable', 'email'],
        ]);

        $store = $request->user()->currentStore();
        $manager = new NotificationManager($store);

        // Use provided data or sample data
        $data = $validated['data'] ?? [
            'store' => $store->toArray(),
            'order' => [
                'number' => 'TEST-12345',
                'total' => 99.99,
            ],
            'customer' => [
                'name' => 'Test Customer',
                'email' => $validated['recipient'] ?? $request->user()->email,
            ],
            'product' => [
                'title' => 'Test Product',
                'sku' => 'TEST-SKU',
                'price' => 49.99,
            ],
        ];

        $data['store'] = $store;
        $data['store']->load('owner');

        $logs = $manager->sendSubscription($notificationSubscription, $data);

        return response()->json([
            'message' => 'Test notification sent',
            'logs' => $logs,
        ]);
    }

    /**
     * Get notification logs for a subscription.
     */
    public function logs(Request $request, NotificationSubscription $notificationSubscription): JsonResponse
    {
        $logs = $notificationSubscription->logs()
            ->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json($logs);
    }

    /**
     * Get notification statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $store = $request->user()->currentStore();
        $days = $request->input('days', 30);

        $manager = new NotificationManager($store);
        $stats = $manager->getStats($days);

        return response()->json($stats);
    }

    /**
     * Get recent notification logs.
     */
    public function recentLogs(Request $request): JsonResponse
    {
        $logs = NotificationLog::query()
            ->with(['template:id,name,channel', 'subscription:id,activity,name'])
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json($logs);
    }
}
