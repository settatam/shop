<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\StoreMarketplace;
use App\Services\Platforms\PlatformManager;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected PlatformManager $platformManager,
    ) {}

    /**
     * Display the marketplace integrations page.
     */
    public function index(): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $connections = StoreMarketplace::where('store_id', $store->id)
            ->orderBy('platform')
            ->orderBy('name')
            ->get()
            ->map(fn (StoreMarketplace $connection) => [
                'id' => $connection->id,
                'platform' => $connection->platform->value,
                'platform_label' => $connection->platform->label(),
                'name' => $connection->name,
                'shop_domain' => $connection->shop_domain,
                'external_store_id' => $connection->external_store_id,
                'status' => $connection->status,
                'is_connected' => $connection->connected_successfully,
                'last_sync_at' => $connection->last_sync_at?->toIso8601String(),
                'last_error' => $connection->last_error,
                'created_at' => $connection->created_at->toIso8601String(),
            ]);

        // Available platforms that can be connected
        $availablePlatforms = collect([
            Platform::Shopify,
            Platform::Ebay,
            Platform::Amazon,
            Platform::Etsy,
            Platform::Walmart,
            Platform::WooCommerce,
        ])->map(fn (Platform $platform) => [
            'value' => $platform->value,
            'label' => $platform->label(),
            'description' => $this->getPlatformDescription($platform),
            'auth_type' => $this->getPlatformAuthType($platform),
            'requires_credentials' => $this->platformRequiresCredentials($platform),
        ]);

        return Inertia::render('settings/Marketplaces', [
            'connections' => $connections,
            'availablePlatforms' => $availablePlatforms,
        ]);
    }

    /**
     * Initiate OAuth connection for a platform.
     */
    public function connect(Request $request, string $platform): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $platformEnum = Platform::from($platform);

        $service = $this->platformManager->getService($platformEnum);

        // Store the marketplace name in session for after callback
        $name = $request->input('name', $platformEnum->label());
        session(['marketplace_connect_name' => $name]);

        return $service->connect($store, [
            'name' => $name,
        ]);
    }

    /**
     * Handle OAuth callback from platform.
     */
    public function callback(Request $request, string $platform): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $platformEnum = Platform::from($platform);

        try {
            $service = $this->platformManager->getService($platformEnum);
            $connection = $service->handleCallback($request, $store);

            // Update with custom name if provided
            $name = session('marketplace_connect_name');
            if ($name && $name !== $platformEnum->label()) {
                $connection->update(['name' => $name]);
            }
            session()->forget('marketplace_connect_name');

            return redirect()->route('settings.marketplaces.index')
                ->with('success', "{$platformEnum->label()} connected successfully!");
        } catch (\Exception $e) {
            return redirect()->route('settings.marketplaces.index')
                ->with('error', "Failed to connect {$platformEnum->label()}: {$e->getMessage()}");
        }
    }

    /**
     * Store a new marketplace connection with credentials (non-OAuth).
     */
    public function store(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $validated = $request->validate([
            'platform' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'shop_domain' => ['nullable', 'string', 'max:255'],
            'credentials' => ['nullable', 'array'],
            'credentials.api_key' => ['nullable', 'string'],
            'credentials.api_secret' => ['nullable', 'string'],
            'credentials.access_token' => ['nullable', 'string'],
            'credentials.seller_id' => ['nullable', 'string'],
            'credentials.marketplace_id' => ['nullable', 'string'],
        ]);

        $platformEnum = Platform::from($validated['platform']);
        $service = $this->platformManager->getService($platformEnum);

        // For platforms with special credential connection methods
        if ($platformEnum === Platform::Walmart && method_exists($service, 'connectWithCredentials')) {
            try {
                $connection = $service->connectWithCredentials($store, [
                    'client_id' => $validated['credentials']['api_key'] ?? '',
                    'client_secret' => $validated['credentials']['api_secret'] ?? '',
                    'seller_id' => $validated['credentials']['seller_id'] ?? null,
                    'name' => $validated['name'],
                ]);

                return redirect()->route('settings.marketplaces.index')
                    ->with('success', "{$platformEnum->label()} marketplace connected successfully!");
            } catch (\Exception $e) {
                return redirect()->route('settings.marketplaces.index')
                    ->with('error', "Failed to connect {$platformEnum->label()}: {$e->getMessage()}");
            }
        }

        // Default: create connection and validate
        $connection = StoreMarketplace::create([
            'store_id' => $store->id,
            'platform' => $platformEnum,
            'name' => $validated['name'],
            'shop_domain' => $validated['shop_domain'] ?? null,
            'credentials' => $validated['credentials'] ?? [],
            'status' => 'pending',
            'connected_successfully' => false,
        ]);

        // Try to validate the connection
        try {
            if (method_exists($service, 'validateCredentials')) {
                $service->validateCredentials($connection);
                $connection->update([
                    'status' => 'active',
                    'connected_successfully' => true,
                ]);
            }
        } catch (\Exception $e) {
            $connection->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
            ]);

            return redirect()->route('settings.marketplaces.index')
                ->with('warning', "Marketplace added but connection validation failed: {$e->getMessage()}");
        }

        return redirect()->route('settings.marketplaces.index')
            ->with('success', "{$platformEnum->label()} marketplace added successfully!");
    }

    /**
     * Update a marketplace connection.
     */
    public function update(Request $request, StoreMarketplace $marketplace): RedirectResponse
    {
        $this->authorizeMarketplace($marketplace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'shop_domain' => ['nullable', 'string', 'max:255'],
            'settings' => ['nullable', 'array'],
        ]);

        $marketplace->update($validated);

        return redirect()->route('settings.marketplaces.index')
            ->with('success', 'Marketplace updated successfully!');
    }

    /**
     * Disconnect/delete a marketplace connection.
     */
    public function destroy(StoreMarketplace $marketplace): RedirectResponse
    {
        $this->authorizeMarketplace($marketplace);

        $platformLabel = $marketplace->platform->label();

        try {
            $service = $this->platformManager->getService($marketplace->platform);
            $service->disconnect($marketplace);
        } catch (\Exception $e) {
            // Still delete even if disconnect API call fails
        }

        $marketplace->delete();

        return redirect()->route('settings.marketplaces.index')
            ->with('success', "{$platformLabel} disconnected successfully!");
    }

    /**
     * Test/refresh a marketplace connection.
     */
    public function test(StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeMarketplace($marketplace);

        try {
            $service = $this->platformManager->getService($marketplace->platform);

            // Refresh token if needed
            if ($marketplace->token_expires_at && $marketplace->token_expires_at->isPast()) {
                $marketplace = $service->refreshToken($marketplace);
            }

            // Test the connection
            if (method_exists($service, 'testConnection')) {
                $service->testConnection($marketplace);
            }

            $marketplace->update([
                'status' => 'active',
                'connected_successfully' => true,
                'last_error' => null,
                'last_sync_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Connection successful!',
            ]);
        } catch (\Exception $e) {
            $marketplace->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Sync data from a marketplace.
     */
    public function sync(StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeMarketplace($marketplace);

        try {
            $service = $this->platformManager->getService($marketplace->platform);

            // Queue sync job or run synchronously for small syncs
            if (method_exists($service, 'syncAll')) {
                $service->syncAll($marketplace);
            }

            $marketplace->update([
                'last_sync_at' => now(),
                'last_error' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sync started successfully!',
            ]);
        } catch (\Exception $e) {
            $marketplace->update([
                'last_error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Authorize that the marketplace belongs to the current store.
     */
    protected function authorizeMarketplace(StoreMarketplace $marketplace): void
    {
        $store = $this->storeContext->getCurrentStore();

        if ($marketplace->store_id !== $store->id) {
            abort(403, 'Unauthorized');
        }
    }

    /**
     * Get platform description.
     */
    protected function getPlatformDescription(Platform $platform): string
    {
        return match ($platform) {
            Platform::Shopify => 'Connect your Shopify store to sync products and orders.',
            Platform::Ebay => 'List and sell products on eBay marketplace.',
            Platform::Amazon => 'Sell on Amazon and manage your listings.',
            Platform::Etsy => 'Connect to Etsy for handmade and vintage items.',
            Platform::Walmart => 'List products on Walmart Marketplace.',
            Platform::WooCommerce => 'Sync with your WooCommerce store.',
            default => 'Connect to this marketplace.',
        };
    }

    /**
     * Get platform auth type.
     */
    protected function getPlatformAuthType(Platform $platform): string
    {
        return match ($platform) {
            Platform::Shopify, Platform::Ebay, Platform::Amazon, Platform::Etsy => 'oauth',
            Platform::WooCommerce => 'credentials',
            Platform::Walmart => 'credentials',
            default => 'credentials',
        };
    }

    /**
     * Check if platform requires manual credentials.
     */
    protected function platformRequiresCredentials(Platform $platform): bool
    {
        return match ($platform) {
            Platform::WooCommerce, Platform::Walmart => true,
            default => false,
        };
    }
}
