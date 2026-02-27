<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreEbayFulfillmentPolicyRequest;
use App\Http\Requests\Settings\StoreEbayLocationRequest;
use App\Http\Requests\Settings\StoreEbayPaymentPolicyRequest;
use App\Http\Requests\Settings\StoreEbayReturnPolicyRequest;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Ebay\EbayAccountService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EbayAccountController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected EbayAccountService $ebayAccountService
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Return Policies
    // ──────────────────────────────────────────────────────────────

    public function returnPolicies(StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->getReturnPolicies($marketplace)
        );
    }

    public function storeReturnPolicy(StoreEbayReturnPolicyRequest $request, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->createReturnPolicy($marketplace, $request->validated()),
            201
        );
    }

    public function updateReturnPolicy(Request $request, StoreMarketplace $marketplace, string $policyId): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->updateReturnPolicy($marketplace, $policyId, $request->all())
        );
    }

    public function destroyReturnPolicy(StoreMarketplace $marketplace, string $policyId): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->deleteReturnPolicy($marketplace, $policyId)
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Fulfillment Policies
    // ──────────────────────────────────────────────────────────────

    public function fulfillmentPolicies(StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->getFulfillmentPolicies($marketplace)
        );
    }

    public function storeFulfillmentPolicy(StoreEbayFulfillmentPolicyRequest $request, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->createFulfillmentPolicy($marketplace, $request->validated()),
            201
        );
    }

    public function updateFulfillmentPolicy(Request $request, StoreMarketplace $marketplace, string $policyId): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->updateFulfillmentPolicy($marketplace, $policyId, $request->all())
        );
    }

    public function destroyFulfillmentPolicy(StoreMarketplace $marketplace, string $policyId): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->deleteFulfillmentPolicy($marketplace, $policyId)
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Payment Policies
    // ──────────────────────────────────────────────────────────────

    public function paymentPolicies(StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->getPaymentPolicies($marketplace)
        );
    }

    public function storePaymentPolicy(StoreEbayPaymentPolicyRequest $request, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->createPaymentPolicy($marketplace, $request->validated()),
            201
        );
    }

    public function updatePaymentPolicy(Request $request, StoreMarketplace $marketplace, string $policyId): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->updatePaymentPolicy($marketplace, $policyId, $request->all())
        );
    }

    public function destroyPaymentPolicy(StoreMarketplace $marketplace, string $policyId): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->deletePaymentPolicy($marketplace, $policyId)
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Inventory Locations
    // ──────────────────────────────────────────────────────────────

    public function locations(StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->getLocations($marketplace)
        );
    }

    public function storeLocation(StoreEbayLocationRequest $request, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        $data = $request->validated();
        $locationKey = $data['location_key'];
        unset($data['location_key']);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->createLocation($marketplace, $locationKey, $data),
            201
        );
    }

    public function updateLocation(Request $request, StoreMarketplace $marketplace, string $locationKey): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->updateLocation($marketplace, $locationKey, $request->all())
        );
    }

    public function destroyLocation(StoreMarketplace $marketplace, string $locationKey): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->deleteLocation($marketplace, $locationKey)
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Privileges & Programs
    // ──────────────────────────────────────────────────────────────

    public function privileges(StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(
            fn () => $this->ebayAccountService->getPrivileges($marketplace)
        );
    }

    public function programs(StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        return $this->tryOrFail(function () use ($marketplace) {
            $result = $this->ebayAccountService->getOptedInPrograms($marketplace);

            // Sync opted-in programs to local settings
            $programTypes = collect($result['programs'] ?? [])
                ->pluck('programType')
                ->values()
                ->all();

            $this->syncProgramsToSettings($marketplace, $programTypes);

            return $result;
        });
    }

    public function optInToProgram(Request $request, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        $request->validate(['program_type' => ['required', 'string']]);

        return $this->tryOrFail(function () use ($request, $marketplace) {
            $result = $this->ebayAccountService->optInToProgram($marketplace, $request->input('program_type'));

            // Add program to local settings
            $settings = $marketplace->settings ?? [];
            $programs = $settings['programs'] ?? [];
            if (! in_array($request->input('program_type'), $programs)) {
                $programs[] = $request->input('program_type');
            }
            $settings['programs'] = $programs;
            $marketplace->update(['settings' => $settings]);

            return $result;
        });
    }

    public function optOutOfProgram(Request $request, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorizeEbayMarketplace($marketplace);

        $request->validate(['program_type' => ['required', 'string']]);

        return $this->tryOrFail(function () use ($request, $marketplace) {
            $result = $this->ebayAccountService->optOutOfProgram($marketplace, $request->input('program_type'));

            // Remove program from local settings
            $settings = $marketplace->settings ?? [];
            $programs = $settings['programs'] ?? [];
            $programs = array_values(array_filter($programs, fn ($p) => $p !== $request->input('program_type')));
            $settings['programs'] = $programs;
            $marketplace->update(['settings' => $settings]);

            return $result;
        });
    }

    protected function syncProgramsToSettings(StoreMarketplace $marketplace, array $programTypes): void
    {
        $settings = $marketplace->settings ?? [];
        $settings['programs'] = $programTypes;
        $marketplace->update(['settings' => $settings]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    protected function authorizeEbayMarketplace(StoreMarketplace $marketplace): void
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($marketplace->store_id === $store->id, 403);
        abort_unless($marketplace->platform === Platform::Ebay, 403);
    }

    protected function tryOrFail(callable $callback, int $successStatus = 200): JsonResponse
    {
        try {
            $result = $callback();

            return response()->json($result, $successStatus);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
