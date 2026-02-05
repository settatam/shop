<?php

namespace App\Http\Controllers\Web;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\SalesChannel;
use App\Models\StoreIntegration;
use App\Models\StoreMarketplace;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationsController extends Controller
{
    protected string $shopifyApiVersion = '2024-01';

    public function index(StoreContext $storeContext): Response
    {
        $storeId = $storeContext->getCurrentStoreId();

        $integrations = StoreIntegration::where('store_id', $storeId)
            ->get()
            ->keyBy('provider')
            ->map(function (StoreIntegration $integration) {
                return [
                    'id' => $integration->id,
                    'provider' => $integration->provider,
                    'name' => $integration->name,
                    'environment' => $integration->environment,
                    'status' => $integration->status,
                    'last_error' => $integration->last_error,
                    'last_used_at' => $integration->last_used_at?->toIso8601String(),
                    'has_credentials' => ! empty($integration->credentials),
                ];
            });

        // Get platform connections (Shopify, eBay, etc.)
        $platforms = StoreMarketplace::where('store_id', $storeId)
            ->get()
            ->map(fn (StoreMarketplace $connection) => [
                'id' => $connection->id,
                'platform' => $connection->platform->value,
                'name' => $connection->name,
                'shop_domain' => $connection->shop_domain,
                'status' => $connection->status,
                'last_error' => $connection->last_error,
                'last_sync_at' => $connection->last_sync_at?->toIso8601String(),
                'settings' => $connection->settings,
            ]);

        return Inertia::render('integrations/Index', [
            'integrations' => $integrations,
            'platforms' => $platforms,
        ]);
    }

    public function storeFedex(Request $request, StoreContext $storeContext): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string'],
            'client_secret' => ['required', 'string'],
            'account_number' => ['required', 'string'],
            'environment' => ['required', 'in:sandbox,production'],
        ]);

        $storeId = $storeContext->getCurrentStoreId();

        StoreIntegration::updateOrCreate(
            [
                'store_id' => $storeId,
                'provider' => StoreIntegration::PROVIDER_FEDEX,
            ],
            [
                'name' => 'FedEx',
                'environment' => $validated['environment'],
                'credentials' => [
                    'client_id' => $validated['client_id'],
                    'client_secret' => $validated['client_secret'],
                    'account_number' => $validated['account_number'],
                ],
                'status' => StoreIntegration::STATUS_ACTIVE,
            ]
        );

        return back()->with('success', 'FedEx integration saved successfully.');
    }

    public function storeTwilio(Request $request, StoreContext $storeContext): RedirectResponse
    {
        $validated = $request->validate([
            'account_sid' => ['required', 'string'],
            'auth_token' => ['required', 'string'],
            'phone_number' => ['required', 'string'],
            'messaging_service_sid' => ['nullable', 'string'],
            'environment' => ['required', 'in:sandbox,production'],
        ]);

        $storeId = $storeContext->getCurrentStoreId();

        StoreIntegration::updateOrCreate(
            [
                'store_id' => $storeId,
                'provider' => StoreIntegration::PROVIDER_TWILIO,
            ],
            [
                'name' => 'Twilio',
                'environment' => $validated['environment'],
                'credentials' => [
                    'account_sid' => $validated['account_sid'],
                    'auth_token' => $validated['auth_token'],
                    'phone_number' => $validated['phone_number'],
                    'messaging_service_sid' => $validated['messaging_service_sid'],
                ],
                'status' => StoreIntegration::STATUS_ACTIVE,
            ]
        );

        return back()->with('success', 'Twilio integration saved successfully.');
    }

    public function storeGia(Request $request, StoreContext $storeContext): RedirectResponse
    {
        $validated = $request->validate([
            'api_key' => ['required', 'string'],
            'api_url' => ['nullable', 'string', 'url'],
        ]);

        $storeId = $storeContext->getCurrentStoreId();

        StoreIntegration::updateOrCreate(
            [
                'store_id' => $storeId,
                'provider' => StoreIntegration::PROVIDER_GIA,
            ],
            [
                'name' => 'GIA',
                'environment' => StoreIntegration::ENV_PRODUCTION,
                'credentials' => [
                    'api_key' => $validated['api_key'],
                    'api_url' => $validated['api_url'] ?? 'https://api.gia.edu/graphql',
                ],
                'status' => StoreIntegration::STATUS_ACTIVE,
            ]
        );

        return back()->with('success', 'GIA integration saved successfully.');
    }

    public function storeShipStation(Request $request, StoreContext $storeContext): RedirectResponse
    {
        $validated = $request->validate([
            'api_key' => ['required', 'string'],
            'api_secret' => ['required', 'string'],
            'store_id' => ['nullable', 'integer'],
            'auto_sync_orders' => ['nullable', 'boolean'],
        ]);

        $storeId = $storeContext->getCurrentStoreId();

        StoreIntegration::updateOrCreate(
            [
                'store_id' => $storeId,
                'provider' => StoreIntegration::PROVIDER_SHIPSTATION,
            ],
            [
                'name' => 'ShipStation',
                'environment' => StoreIntegration::ENV_PRODUCTION,
                'credentials' => [
                    'api_key' => $validated['api_key'],
                    'api_secret' => $validated['api_secret'],
                    'store_id' => $validated['store_id'] ?? null,
                ],
                'settings' => [
                    'auto_sync_orders' => $validated['auto_sync_orders'] ?? true,
                ],
                'status' => StoreIntegration::STATUS_ACTIVE,
            ]
        );

        return back()->with('success', 'ShipStation integration saved successfully.');
    }

    public function storeAnthropic(Request $request, StoreContext $storeContext): RedirectResponse
    {
        $validated = $request->validate([
            'api_key' => ['required', 'string'],
            'model' => ['nullable', 'string'],
        ]);

        $storeId = $storeContext->getCurrentStoreId();

        StoreIntegration::updateOrCreate(
            [
                'store_id' => $storeId,
                'provider' => StoreIntegration::PROVIDER_ANTHROPIC,
            ],
            [
                'name' => 'Anthropic',
                'environment' => StoreIntegration::ENV_PRODUCTION,
                'credentials' => [
                    'api_key' => $validated['api_key'],
                    'model' => $validated['model'] ?? 'claude-sonnet-4-20250514',
                ],
                'status' => StoreIntegration::STATUS_ACTIVE,
            ]
        );

        return back()->with('success', 'Anthropic integration saved successfully.');
    }

    public function storeSerpApi(Request $request, StoreContext $storeContext): RedirectResponse
    {
        $validated = $request->validate([
            'api_key' => ['required', 'string'],
        ]);

        $storeId = $storeContext->getCurrentStoreId();

        StoreIntegration::updateOrCreate(
            [
                'store_id' => $storeId,
                'provider' => StoreIntegration::PROVIDER_SERPAPI,
            ],
            [
                'name' => 'SerpAPI',
                'environment' => StoreIntegration::ENV_PRODUCTION,
                'credentials' => [
                    'api_key' => $validated['api_key'],
                ],
                'status' => StoreIntegration::STATUS_ACTIVE,
            ]
        );

        return back()->with('success', 'SerpAPI integration saved successfully.');
    }

    public function destroy(StoreIntegration $integration, StoreContext $storeContext): RedirectResponse
    {
        $storeId = $storeContext->getCurrentStoreId();

        if ($integration->store_id !== $storeId) {
            abort(403);
        }

        $integration->delete();

        return back()->with('success', 'Integration removed successfully.');
    }

    // Platform (Marketplace) Methods

    public function storeShopify(Request $request, StoreContext $storeContext): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'shop_domain' => ['required', 'string', 'max:255'],
            'access_token' => ['required', 'string'],
        ]);

        $storeId = $storeContext->getCurrentStoreId();

        // Normalize domain
        $shopDomain = $this->normalizeShopifyDomain($validated['shop_domain']);

        // Validate credentials
        $validationResult = $this->validateShopifyCredentials($shopDomain, $validated['access_token']);

        if (! $validationResult['success']) {
            return back()->withErrors(['access_token' => $validationResult['error']]);
        }

        $connection = StoreMarketplace::updateOrCreate(
            [
                'store_id' => $storeId,
                'platform' => Platform::Shopify,
                'shop_domain' => $shopDomain,
            ],
            [
                'name' => $validated['name'],
                'access_token' => $validated['access_token'],
                'external_store_id' => (string) ($validationResult['shop_data']['shop_id'] ?? ''),
                'settings' => $validationResult['shop_data'],
                'status' => 'active',
                'last_error' => null,
            ]
        );

        // Auto-create sales channel
        $this->createSalesChannelForConnection($connection, $storeId);

        return back()->with('success', 'Shopify connected successfully!');
    }

    public function testPlatform(StoreMarketplace $platform, StoreContext $storeContext): RedirectResponse
    {
        $storeId = $storeContext->getCurrentStoreId();

        if ($platform->store_id !== $storeId) {
            abort(403);
        }

        try {
            $result = match ($platform->platform) {
                Platform::Shopify => $this->validateShopifyCredentials($platform->shop_domain, $platform->access_token),
                default => ['success' => false, 'error' => 'Platform testing not implemented'],
            };

            if ($result['success']) {
                $platform->update([
                    'status' => 'active',
                    'last_error' => null,
                    'last_sync_at' => now(),
                ]);

                return back()->with('success', 'Connection test successful!');
            }

            $platform->update([
                'status' => 'error',
                'last_error' => $result['error'],
            ]);

            return back()->withErrors(['platform' => $result['error']]);
        } catch (\Exception $e) {
            Log::error('Platform connection test failed', [
                'platform_id' => $platform->id,
                'error' => $e->getMessage(),
            ]);

            $platform->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
            ]);

            return back()->withErrors(['platform' => 'Connection test failed: '.$e->getMessage()]);
        }
    }

    public function destroyPlatform(StoreMarketplace $platform, StoreContext $storeContext): RedirectResponse
    {
        $storeId = $storeContext->getCurrentStoreId();

        if ($platform->store_id !== $storeId) {
            abort(403);
        }

        $platformName = $platform->platform->label();
        $platform->delete();

        return back()->with('success', "{$platformName} connection removed.");
    }

    // Helper methods

    protected function normalizeShopifyDomain(string $domain): string
    {
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        if (! str_contains($domain, '.myshopify.com') && ! str_contains($domain, '.')) {
            $domain .= '.myshopify.com';
        }

        return $domain;
    }

    protected function validateShopifyCredentials(string $domain, string $token): array
    {
        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->get("https://{$domain}/admin/api/{$this->shopifyApiVersion}/shop.json");

            if ($response->failed()) {
                return [
                    'success' => false,
                    'error' => 'Invalid credentials. Status: '.$response->status(),
                ];
            }

            $shop = $response->json()['shop'] ?? null;

            if (! $shop) {
                return [
                    'success' => false,
                    'error' => 'Invalid response from Shopify',
                ];
            }

            return [
                'success' => true,
                'shop_data' => [
                    'shop_id' => $shop['id'],
                    'shop_name' => $shop['name'],
                    'email' => $shop['email'],
                    'currency' => $shop['currency'],
                    'timezone' => $shop['iana_timezone'],
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Connection failed: '.$e->getMessage(),
            ];
        }
    }

    protected function createSalesChannelForConnection(StoreMarketplace $connection, int $storeId): void
    {
        $existingChannel = SalesChannel::where('store_id', $storeId)
            ->where('store_marketplace_id', $connection->id)
            ->first();

        if ($existingChannel) {
            return;
        }

        $existingByType = SalesChannel::where('store_id', $storeId)
            ->where('type', $connection->platform->value)
            ->whereNull('store_marketplace_id')
            ->first();

        if ($existingByType) {
            $existingByType->update(['store_marketplace_id' => $connection->id]);

            return;
        }

        $code = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $connection->name));
        $baseCode = $code;
        $counter = 1;
        while (SalesChannel::where('store_id', $storeId)->where('code', $code)->exists()) {
            $code = $baseCode.'_'.$counter;
            $counter++;
        }

        SalesChannel::create([
            'store_id' => $storeId,
            'name' => $connection->name,
            'code' => $code,
            'type' => $connection->platform->value,
            'is_local' => false,
            'store_marketplace_id' => $connection->id,
            'is_active' => true,
            'is_default' => false,
        ]);
    }
}
