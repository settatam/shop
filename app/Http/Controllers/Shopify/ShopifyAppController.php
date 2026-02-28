<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Shopify\ShopifyAppInstallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyAppController extends Controller
{
    /**
     * Redirect the merchant to Shopify's OAuth authorization screen.
     */
    public function install(Request $request): RedirectResponse
    {
        $request->validate([
            'shop' => ['required', 'string', 'regex:/^[a-zA-Z0-9\-]+\.myshopify\.com$/'],
        ]);

        $shop = $request->input('shop');
        $scopes = config('services.shopify.scopes');

        $authUrl = "https://{$shop}/admin/oauth/authorize?".http_build_query([
            'client_id' => config('services.shopify.client_id'),
            'scope' => $scopes,
            'redirect_uri' => route('shopify.app.callback'),
            'state' => csrf_token(),
        ]);

        return redirect()->away($authUrl);
    }

    /**
     * Handle Shopify OAuth callback — exchange code for token and provision the store.
     */
    public function callback(Request $request, ShopifyAppInstallService $installService): RedirectResponse
    {
        $shop = $request->input('shop');
        $code = $request->input('code');
        $hmac = $request->input('hmac');

        if (! $this->verifyCallbackHmac($request->query(), $hmac)) {
            Log::warning('Shopify app callback HMAC verification failed', ['shop' => $shop]);

            return redirect()->away("https://{$shop}/admin")
                ->with('error', 'Invalid signature');
        }

        // Exchange authorization code for access token
        $response = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => config('services.shopify.client_id'),
            'client_secret' => config('services.shopify.client_secret'),
            'code' => $code,
        ]);

        if ($response->failed()) {
            Log::error('Shopify token exchange failed', [
                'shop' => $shop,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return redirect()->away("https://{$shop}/admin");
        }

        $tokenData = $response->json();
        $accessToken = $tokenData['access_token'];

        $installService->provision($shop, $accessToken, $tokenData);

        $appHandle = 'shopmata-ai-assistant';

        return redirect()->away("https://{$shop}/admin/apps/{$appHandle}");
    }

    /**
     * GDPR: Customer data request (mandatory Shopify endpoint).
     *
     * We don't store customer PII outside of order data, so we acknowledge the request.
     */
    public function customerDataRequest(Request $request): JsonResponse
    {
        if (! $this->verifyWebhookHmac($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        Log::info('Shopify GDPR customer data request received', [
            'shop_domain' => $request->input('shop_domain'),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * GDPR: Customer data erasure (mandatory Shopify endpoint).
     *
     * We don't store customer PII outside of order data, so we acknowledge the request.
     */
    public function customerDataErasure(Request $request): JsonResponse
    {
        if (! $this->verifyWebhookHmac($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        Log::info('Shopify GDPR customer data erasure received', [
            'shop_domain' => $request->input('shop_domain'),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * GDPR: Shop data erasure (mandatory Shopify endpoint).
     *
     * Deactivates the marketplace connection for the shop.
     */
    public function shopDataErasure(Request $request): JsonResponse
    {
        if (! $this->verifyWebhookHmac($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $shopDomain = $request->input('shop_domain');

        $marketplace = StoreMarketplace::where('shop_domain', $shopDomain)
            ->where('platform', 'shopify')
            ->first();

        if ($marketplace) {
            $marketplace->update(['status' => 'inactive']);

            Log::info('Shopify GDPR shop data erasure — marketplace deactivated', [
                'shop_domain' => $shopDomain,
                'marketplace_id' => $marketplace->id,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Verify the HMAC signature on Shopify OAuth callbacks.
     *
     * @param  array<string, mixed>  $queryParams
     */
    protected function verifyCallbackHmac(array $queryParams, ?string $hmac): bool
    {
        if (! $hmac) {
            return false;
        }

        $secret = config('services.shopify.client_secret');
        if (! $secret) {
            return false;
        }

        // Remove hmac from params before computing
        unset($queryParams['hmac']);
        ksort($queryParams);

        $message = http_build_query($queryParams);
        $calculatedHmac = hash_hmac('sha256', $message, $secret);

        return hash_equals($calculatedHmac, $hmac);
    }

    /**
     * Verify the HMAC signature on Shopify GDPR webhooks.
     */
    protected function verifyWebhookHmac(Request $request): bool
    {
        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');

        if (! $hmacHeader) {
            return false;
        }

        $secret = config('services.shopify.webhook_secret');
        if (! $secret) {
            return true;
        }

        $calculatedHmac = base64_encode(
            hash_hmac('sha256', $request->getContent(), $secret, true)
        );

        return hash_equals($calculatedHmac, $hmacHeader);
    }
}
