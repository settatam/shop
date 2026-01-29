<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Role;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RolesController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    /**
     * Display the roles management page.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $roles = Role::where('store_id', $store->id)
            ->withCount('storeUsers')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'permissions' => array_values((array) ($role->permissions ?? [])),
                'is_system' => $role->is_system,
                'is_default' => $role->is_default,
                'store_users_count' => $role->store_users_count,
            ]);

        // Get all permissions grouped by category
        $definitions = Activity::getDefinitions();
        $permissionsGrouped = collect($definitions)
            ->map(fn ($def, $slug) => array_merge($def, ['slug' => $slug]))
            ->groupBy('category')
            ->map(fn ($items) => $items->values()->toArray())
            ->toArray();

        // Category display names
        $categories = [
            'products' => 'Products',
            'orders' => 'Orders',
            'inventory' => 'Inventory',
            'customers' => 'Customers',
            'integrations' => 'Integrations',
            'store' => 'Store Settings',
            'team' => 'Team',
            'reports' => 'Reports',
        ];

        $presets = Activity::getRolePresets();

        return Inertia::render('settings/Roles', [
            'roles' => $roles,
            'permissionsGrouped' => $permissionsGrouped,
            'categories' => $categories,
            'presets' => $presets,
        ]);
    }
}
