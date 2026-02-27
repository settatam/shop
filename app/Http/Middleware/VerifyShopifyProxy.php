<?php

namespace App\Http\Middleware;

use App\Models\StorefrontApiToken;
use App\Models\StoreMarketplace;
use App\Services\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyProxy
{
    public function __construct(protected StoreContext $storeContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->query('signature');
        $shop = $request->query('shop');

        if (! $signature || ! $shop) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $this->verifySignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $marketplace = StoreMarketplace::where('shop_domain', $shop)
            ->where('status', 'active')
            ->first();

        if (! $marketplace) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $token = StorefrontApiToken::where('store_marketplace_id', $marketplace->id)
            ->where('is_active', true)
            ->first();

        if (! $token) {
            return response()->json(['error' => 'Assistant not enabled'], 403);
        }

        $token->update(['last_used_at' => now()]);

        $this->storeContext->setCurrentStore($marketplace->store);

        $request->attributes->set('marketplace', $marketplace);
        $request->attributes->set('storefront_token', $token);

        return $next($request);
    }

    /**
     * Verify the Shopify App Proxy HMAC signature.
     *
     * Shopify signs proxy requests by sorting all query params (except signature),
     * joining as key=value pairs, and computing HMAC-SHA256 with the app's client secret.
     */
    protected function verifySignature(Request $request): bool
    {
        $secret = config('services.shopify.client_secret');

        if (! $secret) {
            return false;
        }

        $queryParams = $request->query();
        unset($queryParams['signature']);

        ksort($queryParams);

        $parts = [];
        foreach ($queryParams as $key => $value) {
            $parts[] = "{$key}={$value}";
        }

        $message = implode('', $parts);
        $calculatedHmac = hash_hmac('sha256', $message, $secret);
        $signature = $request->query('signature', '');

        return hash_equals($calculatedHmac, $signature);
    }
}
