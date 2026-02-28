<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Models\StorefrontApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShopifyEmbeddedController extends Controller
{
    /**
     * Show the embedded admin settings page.
     */
    public function index(): View
    {
        return view('shopify.embedded', [
            'shopifyApiKey' => config('services.shopify.client_id'),
        ]);
    }

    /**
     * Get current widget settings for the marketplace.
     */
    public function getSettings(Request $request): JsonResponse
    {
        $marketplace = $request->attributes->get('marketplace');

        $token = StorefrontApiToken::where('store_marketplace_id', $marketplace->id)
            ->where('is_active', true)
            ->first();

        if (! $token) {
            return response()->json(['settings' => []], 200);
        }

        return response()->json([
            'settings' => $token->settings ?? [],
            'shop_domain' => $marketplace->shop_domain,
            'status' => $marketplace->status,
        ]);
    }

    /**
     * Update widget settings (partial merge).
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assistant_name' => ['sometimes', 'string', 'max:100'],
            'welcome_message' => ['sometimes', 'string', 'max:500'],
            'accent_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $marketplace = $request->attributes->get('marketplace');

        $token = StorefrontApiToken::where('store_marketplace_id', $marketplace->id)
            ->where('is_active', true)
            ->first();

        if (! $token) {
            return response()->json(['error' => 'No active storefront token'], 404);
        }

        $token->update([
            'settings' => array_merge($token->settings ?? [], $validated),
        ]);

        return response()->json([
            'settings' => $token->fresh()->settings,
        ]);
    }
}
