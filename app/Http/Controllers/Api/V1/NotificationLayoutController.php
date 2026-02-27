<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Models\NotificationLayout;
use App\Services\Notifications\NotificationDataPreparer;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NotificationLayoutController extends Controller
{
    public function __construct(
        protected NotificationDataPreparer $dataPreparer,
        protected StoreContext $storeContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = NotificationLayout::query()
            ->withCount('templates');

        if ($request->has('channel')) {
            $query->forChannel($request->input('channel'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $layouts = $query->orderBy('channel')->orderBy('name')->get();

        return response()->json($layouts);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'channel' => ['required', 'string', Rule::in(NotificationChannel::TYPES)],
            'content' => ['required', 'string'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $storeId = $request->user()->currentStore()?->id;

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $exists = NotificationLayout::where('store_id', $storeId)
            ->where('slug', $validated['slug'])
            ->where('channel', $validated['channel'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'A layout with this slug already exists for this channel',
            ], 422);
        }

        // If setting as default, unset other defaults for this store+channel
        if ($validated['is_default'] ?? false) {
            NotificationLayout::where('store_id', $storeId)
                ->where('channel', $validated['channel'])
                ->update(['is_default' => false]);
        }

        $layout = NotificationLayout::create([
            ...$validated,
            'store_id' => $storeId,
            'is_enabled' => $validated['is_enabled'] ?? true,
        ]);

        return response()->json($layout, 201);
    }

    public function show(NotificationLayout $notificationLayout): JsonResponse
    {
        $notificationLayout->loadCount('templates');

        return response()->json($notificationLayout);
    }

    public function update(Request $request, NotificationLayout $notificationLayout): JsonResponse
    {
        if ($notificationLayout->is_system && ! $request->user()->isStoreOwner()) {
            return response()->json([
                'message' => 'Cannot modify system layouts',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'string'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        // If setting as default, unset other defaults for this store+channel
        if ($validated['is_default'] ?? false) {
            NotificationLayout::where('store_id', $notificationLayout->store_id)
                ->where('channel', $notificationLayout->channel)
                ->where('id', '!=', $notificationLayout->id)
                ->update(['is_default' => false]);
        }

        $notificationLayout->update($validated);

        return response()->json($notificationLayout);
    }

    public function destroy(NotificationLayout $notificationLayout): JsonResponse
    {
        if ($notificationLayout->is_system) {
            return response()->json([
                'message' => 'Cannot delete system layouts',
            ], 403);
        }

        if ($notificationLayout->templates()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete layout with assigned templates',
            ], 422);
        }

        $notificationLayout->delete();

        return response()->json(null, 204);
    }

    /**
     * Preview a layout with sample body content.
     */
    public function preview(Request $request, NotificationLayout $notificationLayout): JsonResponse
    {
        $sampleData = $this->dataPreparer->getSampleData();
        $sampleBody = $request->input('body', '<h2>Sample Email Content</h2><p>This is a preview of how your layout will look with content inside it.</p>');

        $store = $this->storeContext->getCurrentStore();
        $storeData = $store ? $this->dataPreparer->prepareStore($store) : ($sampleData['store'] ?? []);

        try {
            $content = $notificationLayout->render($sampleBody, $storeData);

            return response()->json([
                'content' => $content,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to render layout',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Duplicate a layout.
     */
    public function duplicate(NotificationLayout $notificationLayout): JsonResponse
    {
        $newLayout = $notificationLayout->replicate();
        $newLayout->name = $notificationLayout->name.' (Copy)';
        $newLayout->slug = $notificationLayout->slug.'-copy';
        $newLayout->is_system = false;
        $newLayout->is_default = false;
        $newLayout->save();

        return response()->json($newLayout, 201);
    }
}
