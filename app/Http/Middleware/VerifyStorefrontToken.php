<?php

namespace App\Http\Middleware;

use App\Models\StorefrontApiToken;
use App\Services\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyStorefrontToken
{
    public function __construct(protected StoreContext $storeContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token = StorefrontApiToken::where('token', $bearerToken)
            ->where('is_active', true)
            ->first();

        if (! $token) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        $marketplace = $token->marketplace;

        if (! $marketplace || $marketplace->status !== 'active') {
            return response()->json(['error' => 'Store not connected'], 403);
        }

        $token->touchLastUsed();

        $this->storeContext->setCurrentStore($marketplace->store);

        $request->attributes->set('marketplace', $marketplace);
        $request->attributes->set('storefront_token', $token);

        return $next($request);
    }
}
