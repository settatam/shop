<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\StoreIntegration;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationsController extends Controller
{
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

        return Inertia::render('integrations/Index', [
            'integrations' => $integrations,
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

    public function destroy(StoreIntegration $integration, StoreContext $storeContext): RedirectResponse
    {
        $storeId = $storeContext->getCurrentStoreId();

        if ($integration->store_id !== $storeId) {
            abort(403);
        }

        $integration->delete();

        return back()->with('success', 'Integration removed successfully.');
    }
}
