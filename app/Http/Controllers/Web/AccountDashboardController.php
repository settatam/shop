<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AccountDashboardController extends Controller
{
    /**
     * Show the account dashboard with all stores the user is owner/admin of.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $user = Auth::user();

        // Get stores where user is owner or admin
        $stores = $this->getOwnerOrAdminStores($user);

        // If user has fewer than 2 stores as owner/admin, redirect to regular dashboard
        if ($stores->count() < 2) {
            return redirect()->route('dashboard');
        }

        // Build store data with transactions and orders lists
        $storeData = $stores->map(function (Store $store) use ($user) {
            // Get recent transactions (last 10)
            $transactions = Transaction::where('store_id', $store->id)
                ->with(['customer', 'user'])
                ->latest()
                ->take(10)
                ->get()
                ->map(fn (Transaction $t) => [
                    'id' => $t->id,
                    'customer_name' => $t->customer?->full_name ?? 'Walk-in',
                    'status' => $t->status,
                    'status_label' => $this->formatStatus($t->status),
                    'type' => $t->type,
                    'total_offer' => $t->total_offer,
                    'created_at' => $t->created_at?->format('M d, Y'),
                    'created_at_time' => $t->created_at?->format('g:i A'),
                ]);

            // Get recent orders (last 10)
            $orders = Order::where('store_id', $store->id)
                ->with(['customer', 'user'])
                ->latest()
                ->take(10)
                ->get()
                ->map(fn (Order $o) => [
                    'id' => $o->id,
                    'invoice_number' => $o->invoice_number,
                    'customer_name' => $o->customer?->full_name ?? 'Walk-in',
                    'status' => $o->status,
                    'status_label' => $this->formatStatus($o->status),
                    'total' => $o->total,
                    'source_platform' => $o->source_platform,
                    'created_at' => $o->created_at?->format('M d, Y'),
                    'created_at_time' => $o->created_at?->format('g:i A'),
                ]);

            // Get transaction counts by status
            $transactionsByStatus = Transaction::where('store_id', $store->id)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // Get order counts by status
            $ordersByStatus = Order::where('store_id', $store->id)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // Determine user's role
            $isOwner = $store->user_id === $user->id;
            $storeUser = StoreUser::where('store_id', $store->id)
                ->where('user_id', $user->id)
                ->with('role')
                ->first();
            $role = $isOwner ? 'owner' : ($storeUser?->role?->slug ?? 'member');

            return [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'logo' => $store->logo,
                'edition' => $store->edition ?? 'standard',
                'is_active' => $store->is_active,
                'role' => $role,
                'role_label' => ucfirst($role),
                'transactions' => $transactions,
                'orders' => $orders,
                'transactions_by_status' => $transactionsByStatus,
                'orders_by_status' => $ordersByStatus,
                'total_transactions' => array_sum($transactionsByStatus),
                'total_orders' => array_sum($ordersByStatus),
            ];
        });

        // Summary stats
        $summary = [
            'total_stores' => $storeData->count(),
            'total_transactions' => $storeData->sum('total_transactions'),
            'total_orders' => $storeData->sum('total_orders'),
        ];

        return Inertia::render('account/Dashboard', [
            'stores' => $storeData,
            'summary' => $summary,
        ]);
    }

    /**
     * Get stores where user is owner or admin.
     */
    protected function getOwnerOrAdminStores($user)
    {
        // Get stores owned by user
        $ownedStoreIds = $user->ownedStores()->pluck('id');

        // Get stores where user has owner or admin role
        $adminStoreIds = StoreUser::where('user_id', $user->id)
            ->whereHas('role', function ($query) {
                $query->whereIn('slug', [Role::OWNER, Role::ADMIN]);
            })
            ->pluck('store_id');

        // Also include stores where user has is_owner flag
        $ownerFlagStoreIds = StoreUser::where('user_id', $user->id)
            ->where('is_owner', true)
            ->pluck('store_id');

        // Combine all
        $allStoreIds = $ownedStoreIds
            ->merge($adminStoreIds)
            ->merge($ownerFlagStoreIds)
            ->unique();

        return Store::whereIn('id', $allStoreIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Format status for display.
     */
    protected function formatStatus(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    /**
     * Update a store's edition.
     */
    public function updateEdition(Request $request, Store $store)
    {
        $user = Auth::user();

        // Check user has access to this store
        if (! $user->hasAccessToStore($store->id)) {
            abort(403, 'You do not have access to this store.');
        }

        $validated = $request->validate([
            'edition' => ['required', 'string', 'in:'.implode(',', array_keys(config('editions.editions', [])))],
        ]);

        $store->update(['edition' => $validated['edition']]);

        return back()->with('success', 'Store edition updated successfully.');
    }
}
