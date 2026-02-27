<?php

namespace App\Http\Controllers\Web;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\SalesChannel;
use App\Models\StorefrontApiToken;
use App\Models\StoreMarketplace;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class PlatformConnectionController extends Controller
{
    protected string $shopifyApiVersion = '2024-01';

    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Display platform connections settings page.
     */
    public function index(): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $connections = StoreMarketplace::where('store_id', $store->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (StoreMarketplace $connection) => [
                'id' => $connection->id,
                'platform' => $connection->platform->value,
                'name' => $connection->name,
                'shop_domain' => $connection->shop_domain,
                'status' => $connection->status,
                'last_error' => $connection->last_error,
                'last_sync_at' => $connection->last_sync_at?->toIso8601String(),
                'created_at' => $connection->created_at->toIso8601String(),
                'settings' => $connection->settings,
            ]);

        $availablePlatforms = collect(Platform::cases())->map(fn (Platform $platform) => [
            'value' => $platform->value,
            'label' => $platform->label(),
            'description' => $this->getPlatformDescription($platform),
            'requires_oauth' => $this->platformRequiresOAuth($platform),
            'fields' => $this->getPlatformFields($platform),
        ]);

        return Inertia::render('settings/PlatformConnections', [
            'connections' => $connections,
            'availablePlatforms' => $availablePlatforms,
        ]);
    }

    /**
     * Store a new platform connection.
     */
    public function store(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $validated = $request->validate([
            'platform' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'shop_domain' => ['nullable', 'string', 'max:255'],
            'access_token' => ['nullable', 'string'],
            'credentials' => ['nullable', 'array'],
        ]);

        $platform = Platform::from($validated['platform']);

        // Normalize shop domain for Shopify
        $shopDomain = $validated['shop_domain'] ?? null;
        if ($platform === Platform::Shopify && $shopDomain) {
            $shopDomain = $this->normalizeShopifyDomain($shopDomain);
        }

        // Validate credentials if provided
        if ($platform === Platform::Shopify && $shopDomain && ! empty($validated['access_token'])) {
            $validationResult = $this->validateShopifyCredentials($shopDomain, $validated['access_token']);

            if (! $validationResult['success']) {
                return back()->withErrors(['access_token' => $validationResult['error']]);
            }

            $settings = $validationResult['shop_data'];
        } else {
            $settings = [];
        }

        $connection = StoreMarketplace::create([
            'store_id' => $store->id,
            'platform' => $platform,
            'name' => $validated['name'],
            'shop_domain' => $shopDomain,
            'access_token' => $validated['access_token'] ?? null,
            'credentials' => $validated['credentials'] ?? null,
            'settings' => $settings,
            'status' => ! empty($validated['access_token']) ? 'active' : 'pending',
        ]);

        // Auto-create a sales channel for this platform
        $this->createSalesChannelForConnection($connection, $store->id);

        return back()->with('success', "Connected to {$platform->label()} successfully.");
    }

    /**
     * Test a platform connection.
     */
    public function test(StoreMarketplace $connection): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($connection->store_id !== $store->id) {
            abort(403);
        }

        try {
            $result = match ($connection->platform) {
                Platform::Shopify => $this->testShopifyConnection($connection),
                Platform::Ebay => $this->testEbayConnection($connection),
                default => ['success' => false, 'error' => 'Platform testing not implemented'],
            };

            if ($result['success']) {
                $connection->update([
                    'status' => 'active',
                    'last_error' => null,
                    'last_sync_at' => now(),
                ]);

                return back()->with('success', 'Connection test successful!');
            }

            $connection->update([
                'status' => 'error',
                'last_error' => $result['error'],
            ]);

            return back()->withErrors(['connection' => $result['error']]);
        } catch (\Exception $e) {
            Log::error('Platform connection test failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            $connection->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
            ]);

            return back()->withErrors(['connection' => 'Connection test failed: '.$e->getMessage()]);
        }
    }

    /**
     * Update a platform connection.
     */
    public function update(Request $request, StoreMarketplace $connection): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($connection->store_id !== $store->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'shop_domain' => ['nullable', 'string', 'max:255'],
            'access_token' => ['nullable', 'string'],
            'credentials' => ['nullable', 'array'],
        ]);

        // If updating Shopify credentials, validate them
        if ($connection->platform === Platform::Shopify) {
            $shopDomain = $validated['shop_domain'] ?? $connection->shop_domain;
            $accessToken = $validated['access_token'] ?? $connection->access_token;

            if ($shopDomain) {
                $shopDomain = $this->normalizeShopifyDomain($shopDomain);
                $validated['shop_domain'] = $shopDomain;
            }

            if (! empty($validated['access_token']) && $shopDomain) {
                $validationResult = $this->validateShopifyCredentials($shopDomain, $validated['access_token']);

                if (! $validationResult['success']) {
                    return back()->withErrors(['access_token' => $validationResult['error']]);
                }

                $validated['settings'] = $validationResult['shop_data'];
                $validated['status'] = 'active';
                $validated['last_error'] = null;
            }
        }

        $connection->update($validated);

        return back()->with('success', 'Connection updated successfully.');
    }

    /**
     * Delete a platform connection.
     */
    public function destroy(StoreMarketplace $connection): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($connection->store_id !== $store->id) {
            abort(403);
        }

        $platformName = $connection->platform->label();
        $connection->delete();

        return back()->with('success', "{$platformName} connection removed.");
    }

    /**
     * Initiate OAuth flow for platforms that require it.
     */
    public function initiateOAuth(Request $request, string $platform): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $platformEnum = Platform::from($platform);

        return match ($platformEnum) {
            Platform::Shopify => $this->initiateShopifyOAuth($request, $store),
            default => back()->withErrors(['oauth' => 'OAuth not supported for this platform']),
        };
    }

    /**
     * Handle OAuth callback.
     */
    public function handleOAuthCallback(Request $request, string $platform): RedirectResponse
    {
        return match ($platform) {
            'shopify' => $this->handleShopifyCallback($request),
            default => redirect()->route('settings.platforms.index')
                ->withErrors(['oauth' => 'Unknown platform']),
        };
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
                    'error' => 'Invalid credentials: '.$response->status(),
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

    protected function testShopifyConnection(StoreMarketplace $connection): array
    {
        if (! $connection->shop_domain || ! $connection->access_token) {
            return ['success' => false, 'error' => 'Missing shop domain or access token'];
        }

        return $this->validateShopifyCredentials($connection->shop_domain, $connection->access_token);
    }

    protected function testEbayConnection(StoreMarketplace $connection): array
    {
        // TODO: Implement eBay connection testing
        return ['success' => false, 'error' => 'eBay connection testing not yet implemented'];
    }

    protected function initiateShopifyOAuth(Request $request, $store): RedirectResponse
    {
        $shopDomain = $request->input('shop_domain');

        if (! $shopDomain) {
            return back()->withErrors(['shop_domain' => 'Shop domain is required']);
        }

        $shopDomain = $this->normalizeShopifyDomain($shopDomain);
        $redirectUri = route('settings.platforms.oauth.callback', ['platform' => 'shopify']);
        $scopes = implode(',', [
            'read_products',
            'write_products',
            'read_orders',
            'write_orders',
            'read_inventory',
            'write_inventory',
            'read_locations',
        ]);

        $state = encrypt([
            'store_id' => $store->id,
            'shop_domain' => $shopDomain,
        ]);

        $authUrl = "https://{$shopDomain}/admin/oauth/authorize?".http_build_query([
            'client_id' => config('services.shopify.client_id'),
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return redirect()->away($authUrl);
    }

    protected function handleShopifyCallback(Request $request): RedirectResponse
    {
        try {
            $state = decrypt($request->input('state'));
            $storeId = $state['store_id'];
            $shopDomain = $state['shop_domain'];
            $code = $request->input('code');

            $response = Http::post("https://{$shopDomain}/admin/oauth/access_token", [
                'client_id' => config('services.shopify.client_id'),
                'client_secret' => config('services.shopify.client_secret'),
                'code' => $code,
            ]);

            if ($response->failed()) {
                throw new \Exception('Failed to obtain access token');
            }

            $data = $response->json();

            // Get shop info
            $shopResponse = Http::withHeaders([
                'X-Shopify-Access-Token' => $data['access_token'],
            ])->get("https://{$shopDomain}/admin/api/{$this->shopifyApiVersion}/shop.json");

            $shop = $shopResponse->json()['shop'] ?? [];

            $connection = StoreMarketplace::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'platform' => Platform::Shopify,
                    'shop_domain' => $shopDomain,
                ],
                [
                    'name' => $shop['name'] ?? $shopDomain,
                    'access_token' => $data['access_token'],
                    'external_store_id' => (string) ($shop['id'] ?? ''),
                    'credentials' => ['scope' => $data['scope'] ?? null],
                    'settings' => [
                        'shop_name' => $shop['name'] ?? null,
                        'email' => $shop['email'] ?? null,
                        'currency' => $shop['currency'] ?? null,
                        'timezone' => $shop['iana_timezone'] ?? null,
                    ],
                    'status' => 'active',
                ]
            );

            // Auto-create storefront API token for the AI assistant
            $this->ensureStorefrontApiToken($connection);

            // Auto-create sales channel
            $this->createSalesChannelForConnection($connection, $storeId);

            return redirect()->route('settings.platforms.index')
                ->with('success', 'Shopify connected successfully!');
        } catch (\Exception $e) {
            Log::error('Shopify OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect()->route('settings.platforms.index')
                ->withErrors(['oauth' => 'Failed to connect to Shopify: '.$e->getMessage()]);
        }
    }

    protected function createSalesChannelForConnection(StoreMarketplace $connection, int $storeId): void
    {
        // Check if a sales channel already exists for this connection
        $existingChannel = SalesChannel::where('store_id', $storeId)
            ->where('store_marketplace_id', $connection->id)
            ->first();

        if ($existingChannel) {
            return;
        }

        // Check if a sales channel exists for this platform type
        $existingByType = SalesChannel::where('store_id', $storeId)
            ->where('type', $connection->platform->value)
            ->whereNull('store_marketplace_id')
            ->first();

        if ($existingByType) {
            // Link existing channel to this connection
            $existingByType->update(['store_marketplace_id' => $connection->id]);

            return;
        }

        // Create a new sales channel
        $code = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $connection->name));

        // Ensure unique code
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

    /**
     * Ensure a StorefrontApiToken exists for a Shopify connection.
     */
    protected function ensureStorefrontApiToken(StoreMarketplace $connection): void
    {
        StorefrontApiToken::firstOrCreate(
            [
                'store_id' => $connection->store_id,
                'store_marketplace_id' => $connection->id,
            ],
            [
                'token' => StorefrontApiToken::generateToken(),
                'name' => 'Default',
                'is_active' => true,
                'settings' => [
                    'welcome_message' => "Hi! I'm your jewelry assistant. How can I help you today?",
                    'accent_color' => '#1a1a2e',
                ],
            ]
        );
    }

    protected function getPlatformDescription(Platform $platform): string
    {
        return match ($platform) {
            Platform::Shopify => 'Connect your Shopify store to sync products and orders',
            Platform::Ebay => 'List products and receive orders from eBay',
            Platform::Amazon => 'Sell on Amazon marketplace',
            Platform::Etsy => 'Connect to your Etsy shop',
            Platform::Walmart => 'Sell on Walmart marketplace',
            Platform::WooCommerce => 'Connect your WooCommerce store',
        };
    }

    protected function platformRequiresOAuth(Platform $platform): bool
    {
        return match ($platform) {
            Platform::Shopify => true,
            Platform::Ebay => true,
            Platform::Etsy => true,
            default => false,
        };
    }

    protected function getPlatformFields(Platform $platform): array
    {
        return match ($platform) {
            Platform::Shopify => [
                ['name' => 'shop_domain', 'label' => 'Shop Domain', 'type' => 'text', 'placeholder' => 'yourstore.myshopify.com', 'required' => true],
                ['name' => 'access_token', 'label' => 'Admin API Access Token', 'type' => 'password', 'placeholder' => 'shpat_xxxxx', 'required' => false, 'help' => 'Optional if using OAuth. Get from Shopify Admin > Apps > Develop apps'],
            ],
            Platform::Ebay => [
                ['name' => 'environment', 'label' => 'Environment', 'type' => 'select', 'options' => [['value' => 'sandbox', 'label' => 'Sandbox'], ['value' => 'production', 'label' => 'Production']], 'required' => true],
            ],
            Platform::WooCommerce => [
                ['name' => 'shop_domain', 'label' => 'Store URL', 'type' => 'text', 'placeholder' => 'https://yourstore.com', 'required' => true],
                ['name' => 'consumer_key', 'label' => 'Consumer Key', 'type' => 'text', 'required' => true],
                ['name' => 'consumer_secret', 'label' => 'Consumer Secret', 'type' => 'password', 'required' => true],
            ],
            default => [],
        };
    }
}
