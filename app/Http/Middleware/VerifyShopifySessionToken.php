<?php

namespace App\Http\Middleware;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifySessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization', '');
        $token = str_starts_with($authHeader, 'Bearer ')
            ? substr($authHeader, 7)
            : null;

        if (! $token) {
            return response()->json(['error' => 'Missing session token'], 401);
        }

        $secret = config('services.shopify.client_secret');

        if (! $secret) {
            return response()->json(['error' => 'Shopify client secret not configured'], 500);
        }

        try {
            $payload = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Throwable) {
            return response()->json(['error' => 'Invalid session token'], 401);
        }

        // Extract shop domain from the "dest" claim (e.g. "https://shop.myshopify.com")
        $dest = $payload->dest ?? '';
        $shopDomain = preg_replace('#^https?://#', '', rtrim($dest, '/'));

        if (! $shopDomain) {
            return response()->json(['error' => 'Invalid session token payload'], 401);
        }

        $marketplace = StoreMarketplace::where('shop_domain', $shopDomain)
            ->where('platform', Platform::Shopify)
            ->where('status', 'active')
            ->first();

        if (! $marketplace) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $request->attributes->set('marketplace', $marketplace);

        return $next($request);
    }
}
