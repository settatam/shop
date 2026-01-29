<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    /**
     * List all stores the user has access to.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $stores = Store::withoutGlobalScopes()
            ->whereHas('storeUsers', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->orWhere('user_id', $user->id)
            ->get()
            ->map(function ($store) use ($user) {
                $storeUser = StoreUser::where('user_id', $user->id)
                    ->where('store_id', $store->id)
                    ->with('role:id,name,slug')
                    ->first();

                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'initial' => strtoupper(substr($store->name, 0, 1)),
                    'is_owner' => $store->user_id === $user->id || ($storeUser?->is_owner ?? false),
                    'role' => $storeUser?->role,
                    'current' => $this->storeContext->getCurrentStoreId() === $store->id,
                ];
            });

        return response()->json($stores);
    }

    /**
     * Get the current store.
     */
    public function show(): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['message' => 'No store context'], 404);
        }

        return response()->json($store);
    }

    /**
     * Update the current store.
     */
    public function update(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['message' => 'No store context'], 404);
        }

        $store->update($request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'business_name' => ['nullable', 'string', 'max:191'],
            'account_email' => ['nullable', 'email', 'max:191'],
            'customer_email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:15'],
            'address' => ['nullable', 'string', 'max:191'],
            'address2' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:191'],
            'state' => ['nullable', 'string', 'max:191'],
            'zip' => ['nullable', 'string', 'max:10'],
            'country_id' => ['nullable', 'integer'],
            'timezone_id' => ['nullable', 'integer'],
            'currency_id' => ['nullable', 'integer'],
            'meta_title' => ['nullable', 'string'],
            'meta_description' => ['nullable', 'string'],
            'jewelry_module_enabled' => ['sometimes', 'boolean'],
        ]));

        return response()->json($store);
    }

    /**
     * Create a new store for the current user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        // Create the store
        $store = Store::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
            'account_email' => $user->email,
            'is_active' => true,
        ]);

        // Create default roles
        Role::createDefaultRoles($store->id);

        // Get the owner role
        $ownerRole = Role::where('store_id', $store->id)
            ->where('slug', Role::OWNER)
            ->first();

        // Create store user record for the owner
        StoreUser::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $ownerRole?->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => explode(' ', $user->name)[0] ?? $user->name,
            'last_name' => explode(' ', $user->name, 2)[1] ?? '',
            'email' => $user->email,
        ]);

        return response()->json([
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'initial' => strtoupper(substr($store->name, 0, 1)),
            'is_owner' => true,
            'current' => false,
        ], 201);
    }

    /**
     * Switch to a different store.
     */
    public function switchStore(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        // Verify user has access to this store
        $hasAccess = StoreUser::where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->exists();

        if (! $hasAccess && $store->user_id !== $user->id) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        // Update user's current store
        $user->update(['current_store_id' => $store->id]);

        // Update session if available
        if ($request->hasSession()) {
            $request->session()->put('current_store_id', $store->id);
        }

        return response()->json([
            'message' => 'Switched to '.$store->name,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
            ],
        ]);
    }
}
