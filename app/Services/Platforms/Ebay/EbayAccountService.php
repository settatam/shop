<?php

namespace App\Services\Platforms\Ebay;

use App\Models\StoreMarketplace;

class EbayAccountService
{
    public function __construct(
        protected EbayService $ebayService
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Return Policies
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array<mixed>
     */
    public function getReturnPolicies(StoreMarketplace $marketplace): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        $marketplaceId = $marketplace->settings['marketplace_id'] ?? 'EBAY_US';

        $response = $this->ebayService->ebayRequest(
            $marketplace,
            'GET',
            '/sell/account/v1/return_policy',
            ['marketplace_id' => $marketplaceId]
        );

        return $response['returnPolicies'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getReturnPolicy(StoreMarketplace $marketplace, string $policyId): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'GET',
            "/sell/account/v1/return_policy/{$policyId}"
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createReturnPolicy(StoreMarketplace $marketplace, array $data): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'POST',
            '/sell/account/v1/return_policy',
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateReturnPolicy(StoreMarketplace $marketplace, string $policyId, array $data): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'PUT',
            "/sell/account/v1/return_policy/{$policyId}",
            $data
        );
    }

    public function deleteReturnPolicy(StoreMarketplace $marketplace, string $policyId): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'DELETE',
            "/sell/account/v1/return_policy/{$policyId}"
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Fulfillment Policies
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array<mixed>
     */
    public function getFulfillmentPolicies(StoreMarketplace $marketplace): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        $marketplaceId = $marketplace->settings['marketplace_id'] ?? 'EBAY_US';

        $response = $this->ebayService->ebayRequest(
            $marketplace,
            'GET',
            '/sell/account/v1/fulfillment_policy',
            ['marketplace_id' => $marketplaceId]
        );

        return $response['fulfillmentPolicies'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getFulfillmentPolicy(StoreMarketplace $marketplace, string $policyId): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'GET',
            "/sell/account/v1/fulfillment_policy/{$policyId}"
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createFulfillmentPolicy(StoreMarketplace $marketplace, array $data): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'POST',
            '/sell/account/v1/fulfillment_policy',
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateFulfillmentPolicy(StoreMarketplace $marketplace, string $policyId, array $data): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'PUT',
            "/sell/account/v1/fulfillment_policy/{$policyId}",
            $data
        );
    }

    public function deleteFulfillmentPolicy(StoreMarketplace $marketplace, string $policyId): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'DELETE',
            "/sell/account/v1/fulfillment_policy/{$policyId}"
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Payment Policies
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array<mixed>
     */
    public function getPaymentPolicies(StoreMarketplace $marketplace): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        $marketplaceId = $marketplace->settings['marketplace_id'] ?? 'EBAY_US';

        $response = $this->ebayService->ebayRequest(
            $marketplace,
            'GET',
            '/sell/account/v1/payment_policy',
            ['marketplace_id' => $marketplaceId]
        );

        return $response['paymentPolicies'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentPolicy(StoreMarketplace $marketplace, string $policyId): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'GET',
            "/sell/account/v1/payment_policy/{$policyId}"
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createPaymentPolicy(StoreMarketplace $marketplace, array $data): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'POST',
            '/sell/account/v1/payment_policy',
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updatePaymentPolicy(StoreMarketplace $marketplace, string $policyId, array $data): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'PUT',
            "/sell/account/v1/payment_policy/{$policyId}",
            $data
        );
    }

    public function deletePaymentPolicy(StoreMarketplace $marketplace, string $policyId): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'DELETE',
            "/sell/account/v1/payment_policy/{$policyId}"
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Inventory Locations
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array<mixed>
     */
    public function getLocations(StoreMarketplace $marketplace): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        $response = $this->ebayService->ebayRequest(
            $marketplace,
            'GET',
            '/sell/inventory/v1/location',
            ['limit' => 100]
        );

        return $response['locations'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createLocation(StoreMarketplace $marketplace, string $locationKey, array $data): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'POST',
            "/sell/inventory/v1/location/{$locationKey}",
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateLocation(StoreMarketplace $marketplace, string $locationKey, array $data): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'POST',
            "/sell/inventory/v1/location/{$locationKey}/update_location_details",
            $data
        );
    }

    public function deleteLocation(StoreMarketplace $marketplace, string $locationKey): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'DELETE',
            "/sell/inventory/v1/location/{$locationKey}"
        );
    }

    public function enableLocation(StoreMarketplace $marketplace, string $locationKey): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'POST',
            "/sell/inventory/v1/location/{$locationKey}/enable"
        );
    }

    public function disableLocation(StoreMarketplace $marketplace, string $locationKey): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'POST',
            "/sell/inventory/v1/location/{$locationKey}/disable"
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Seller Programs & Privileges
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function getPrivileges(StoreMarketplace $marketplace): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'GET',
            '/sell/account/v1/privilege'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptedInPrograms(StoreMarketplace $marketplace): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'GET',
            '/sell/account/v1/program/get_opted_in_programs'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function optInToProgram(StoreMarketplace $marketplace, string $programType): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'POST',
            '/sell/account/v1/program/opt_in',
            ['programType' => $programType]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function optOutOfProgram(StoreMarketplace $marketplace, string $programType): array
    {
        $this->ebayService->ensureValidToken($marketplace);

        return $this->ebayService->ebayRequest(
            $marketplace,
            'POST',
            '/sell/account/v1/program/opt_out',
            ['programType' => $programType]
        );
    }
}
