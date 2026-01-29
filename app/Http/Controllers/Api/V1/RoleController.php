<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Role::query()->withCount('storeUsers');

        if ($request->has('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        $roles = $request->boolean('all')
            ? $query->orderBy('name')->get()
            : $query->orderBy('name')->paginate($request->input('per_page', 15));

        return response()->json($roles);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('roles')->where('store_id', $request->user()->currentStore()?->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        // Validate permissions exist
        $validPermissions = Activity::getAllSlugs();
        $invalidPermissions = array_diff(
            array_filter($validated['permissions'], fn ($p) => $p !== '*' && ! str_ends_with($p, '.*')),
            $validPermissions
        );

        if (! empty($invalidPermissions)) {
            return response()->json([
                'message' => 'Invalid permissions provided',
                'invalid_permissions' => array_values($invalidPermissions),
            ], 422);
        }

        // If setting as default, unset other defaults
        if ($validated['is_default'] ?? false) {
            Role::where('is_default', true)->update(['is_default' => false]);
        }

        $role = Role::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'permissions' => $validated['permissions'],
            'is_default' => $validated['is_default'] ?? false,
        ]);

        return response()->json($role, 201);
    }

    public function show(Role $role): JsonResponse
    {
        $role->loadCount('storeUsers');
        $role->setAttribute('expanded_permissions', $role->getExpandedPermissions());

        return response()->json($role);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->isSystemRole() && ! $request->user()->isStoreOwner()) {
            return response()->json(['message' => 'Cannot modify system roles'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        // Validate permissions if provided
        if (isset($validated['permissions'])) {
            $validPermissions = Activity::getAllSlugs();
            $invalidPermissions = array_diff(
                array_filter($validated['permissions'], fn ($p) => $p !== '*' && ! str_ends_with($p, '.*')),
                $validPermissions
            );

            if (! empty($invalidPermissions)) {
                return response()->json([
                    'message' => 'Invalid permissions provided',
                    'invalid_permissions' => array_values($invalidPermissions),
                ], 422);
            }
        }

        // If setting as default, unset other defaults
        if ($validated['is_default'] ?? false) {
            Role::where('is_default', true)
                ->where('id', '!=', $role->id)
                ->update(['is_default' => false]);
        }

        $role->update($validated);

        return response()->json($role);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->isSystemRole()) {
            return response()->json(['message' => 'Cannot delete system roles'], 403);
        }

        if ($role->storeUsers()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete role with assigned users. Reassign users first.',
            ], 422);
        }

        $role->delete();

        return response()->json(null, 204);
    }

    /**
     * Get all available permissions grouped by category.
     */
    public function permissions(): JsonResponse
    {
        $definitions = Activity::getDefinitions();
        $grouped = collect($definitions)->groupBy('category');

        return response()->json([
            'permissions' => $definitions,
            'grouped' => $grouped,
            'categories' => array_keys($grouped->toArray()),
        ]);
    }

    /**
     * Get role presets for quick setup.
     */
    public function presets(): JsonResponse
    {
        return response()->json(Activity::getRolePresets());
    }

    /**
     * Sync permissions for a role.
     */
    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        if ($role->isSystemRole() && ! $request->user()->isStoreOwner()) {
            return response()->json(['message' => 'Cannot modify system roles'], 403);
        }

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
        ]);

        $role->syncPermissions($validated['permissions']);

        return response()->json($role);
    }

    /**
     * Duplicate a role.
     */
    public function duplicate(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('roles')->where('store_id', $request->user()->currentStore()?->id),
            ],
        ]);

        $newRole = $role->replicate();
        $newRole->name = $validated['name'] ?? $role->name.' (Copy)';
        $newRole->slug = $validated['slug'] ?? $role->slug.'-copy';
        $newRole->is_default = false;
        $newRole->is_system = false;
        $newRole->save();

        return response()->json($newRole, 201);
    }
}
