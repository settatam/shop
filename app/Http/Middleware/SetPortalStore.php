<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPortalStore
{
    public function __construct(protected StoreContext $storeContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $storeSlug = $request->route('storeSlug');

        $store = Store::withoutGlobalScopes()
            ->where('slug', $storeSlug)
            ->first();

        if (! $store) {
            abort(404);
        }

        $this->storeContext->setCurrentStore($store);

        return $next($request);
    }
}
