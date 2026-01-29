<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Models\StoreUser;
use App\Services\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentStore
{
    public function __construct(protected StoreContext $storeContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $store = $this->resolveStore($request);

        if ($store) {
            $this->storeContext->setCurrentStore($store);
        }

        return $next($request);
    }

    protected function resolveStore(Request $request): ?Store
    {
        // 1. Try to resolve from X-Store-Id header (for API)
        if ($storeId = $request->header('X-Store-Id')) {
            return $this->getStoreForUser($request, (int) $storeId);
        }

        // 2. Try to resolve from subdomain
        if ($store = $this->resolveFromSubdomain($request)) {
            return $store;
        }

        // 3. Try to resolve from session (only for web requests)
        if ($request->hasSession() && $storeId = $request->session()->get('current_store_id')) {
            return $this->getStoreForUser($request, (int) $storeId);
        }

        // 4. Try to resolve from user's saved current_store_id
        $user = $request->user();
        if ($user && $user->current_store_id) {
            $store = $this->getStoreForUser($request, $user->current_store_id);
            if ($store) {
                return $store;
            }
        }

        // 5. Fall back to user's primary/first store
        return $this->getUserPrimaryStore($request);
    }

    protected function resolveFromSubdomain(Request $request): ?Store
    {
        $host = $request->getHost();
        $parts = explode('.', $host);

        if (count($parts) >= 3) {
            $subdomain = $parts[0];

            return Store::withoutGlobalScopes()
                ->where('slug', $subdomain)
                ->orWhere('store_domain', $host)
                ->first();
        }

        return null;
    }

    protected function getStoreForUser(Request $request, int $storeId): ?Store
    {
        $user = $request->user();

        if (! $user) {
            return Store::withoutGlobalScopes()->find($storeId);
        }

        // Verify user has access to this store
        $hasAccess = StoreUser::where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->exists();

        if ($hasAccess) {
            return Store::withoutGlobalScopes()->find($storeId);
        }

        return null;
    }

    protected function getUserPrimaryStore(Request $request): ?Store
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        $storeUser = StoreUser::where('user_id', $user->id)
            ->orderBy('created_at')
            ->first();

        if ($storeUser) {
            return Store::withoutGlobalScopes()->find($storeUser->store_id);
        }

        // If user owns a store directly
        return Store::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->first();
    }
}
