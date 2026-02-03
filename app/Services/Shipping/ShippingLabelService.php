<?php

namespace App\Services\Shipping;

use App\Models\Address;
use App\Models\Customer;
use App\Models\ShippingLabel;
use App\Models\State;
use App\Models\Store;
use App\Models\Transaction;
use App\Services\StoreContext;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ShippingLabelService
{
    protected ?FedExService $fedExService = null;

    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Get the FedEx service for the current store.
     * Uses store-specific credentials if available.
     */
    protected function getFedExService(?Store $store = null): FedExService
    {
        $store = $store ?? $this->storeContext->getCurrentStore();

        if ($this->fedExService && $this->fedExService->getStore()?->id === $store?->id) {
            return $this->fedExService;
        }

        $this->fedExService = $store ? FedExService::forStore($store) : new FedExService;

        return $this->fedExService;
    }

    /**
     * Check if the FedEx service is configured.
     */
    public function isConfigured(?Store $store = null): bool
    {
        return $this->getFedExService($store)->isConfigured();
    }

    /**
     * Create an outbound shipping label (kit to customer).
     *
     * @param  array<string, mixed>  $options  Optional shipping options: service_type, packaging_type, weight, length, width, height
     */
    public function createOutboundLabel(
        Transaction $transaction,
        array $options = []
    ): ShippingLabel {
        $store = $transaction->store;
        $customer = $transaction->customer;

        if (! $customer) {
            throw new InvalidArgumentException('Transaction must have a customer to create a shipping label.');
        }

        $fedExService = $this->getFedExService($store);

        if (! $fedExService->isConfigured()) {
            throw new InvalidArgumentException('FedEx service is not configured.');
        }

        $serviceType = $options['service_type'] ?? config('logistics.fedex.service_type', 'FEDEX_2_DAY');
        $packagingType = $options['packaging_type'] ?? config('logistics.fedex.packaging_type', 'FEDEX_ENVELOPE');

        $senderAddress = $this->getStoreShippingAddress($store);
        $recipientAddress = $this->getTransactionRecipientAddress($transaction);
        $packageDetails = $this->buildPackageDetails($options);

        $result = $fedExService->createShipment(
            $senderAddress,
            $recipientAddress,
            $packageDetails,
            $serviceType,
            'PDF',
            $packagingType
        );

        if (! $result->success) {
            throw new InvalidArgumentException($result->errorMessage ?? 'Failed to create shipping label');
        }

        // Store the label PDF
        $labelPath = $this->storeLabelPdf($result->labelPdf, $transaction, 'outbound');

        return $transaction->shippingLabels()->create([
            'store_id' => $store->id,
            'type' => ShippingLabel::TYPE_OUTBOUND,
            'carrier' => ShippingLabel::CARRIER_FEDEX,
            'tracking_number' => $result->trackingNumber,
            'service_type' => $serviceType,
            'label_format' => 'PDF',
            'label_path' => $labelPath,
            'label_zpl' => $result->labelZpl,
            'shipping_cost' => $result->shippingCost,
            'sender_address' => $senderAddress,
            'recipient_address' => $recipientAddress,
            'shipment_details' => array_merge($packageDetails, ['packaging_type' => $packagingType]),
            'fedex_shipment_id' => $result->shipmentId,
            'status' => ShippingLabel::STATUS_CREATED,
        ]);
    }

    /**
     * Create a return shipping label (customer returns items to store).
     *
     * @param  array<string, mixed>  $options  Optional shipping options: service_type, packaging_type, weight, length, width, height
     */
    public function createReturnLabel(
        Transaction $transaction,
        array $options = []
    ): ShippingLabel {
        $store = $transaction->store;
        $customer = $transaction->customer;

        if (! $customer) {
            throw new InvalidArgumentException('Transaction must have a customer to create a shipping label.');
        }

        $fedExService = $this->getFedExService($store);

        if (! $fedExService->isConfigured()) {
            throw new InvalidArgumentException('FedEx service is not configured.');
        }

        $serviceType = $options['service_type'] ?? config('logistics.fedex.service_type', 'FEDEX_2_DAY');
        $packagingType = $options['packaging_type'] ?? config('logistics.fedex.packaging_type', 'FEDEX_ENVELOPE');

        // For return labels, customer sends TO the store
        $senderAddress = $this->getTransactionRecipientAddress($transaction);
        $recipientAddress = $this->getStoreShippingAddress($store);
        $packageDetails = $this->buildPackageDetails($options);

        $result = $fedExService->createReturnShipment(
            $recipientAddress, // Store address for return delivery
            $senderAddress,    // Customer/transaction address as sender
            $packageDetails,
            $serviceType,
            $packagingType
        );

        if (! $result->success) {
            throw new InvalidArgumentException($result->errorMessage ?? 'Failed to create return label');
        }

        // Store the label PDF
        $labelPath = $this->storeLabelPdf($result->labelPdf, $transaction, 'return');

        return $transaction->shippingLabels()->create([
            'store_id' => $store->id,
            'type' => ShippingLabel::TYPE_RETURN,
            'carrier' => ShippingLabel::CARRIER_FEDEX,
            'tracking_number' => $result->trackingNumber,
            'service_type' => $serviceType,
            'label_format' => 'PDF',
            'label_path' => $labelPath,
            'label_zpl' => $result->labelZpl,
            'shipping_cost' => $result->shippingCost,
            'sender_address' => $senderAddress,
            'recipient_address' => $recipientAddress,
            'shipment_details' => array_merge($packageDetails, ['packaging_type' => $packagingType]),
            'fedex_shipment_id' => $result->shipmentId,
            'status' => ShippingLabel::STATUS_CREATED,
        ]);
    }

    /**
     * Get the label PDF contents.
     */
    public function getLabelPdf(ShippingLabel $label): ?string
    {
        if (! $label->label_path) {
            return null;
        }

        if (Storage::exists($label->label_path)) {
            return Storage::get($label->label_path);
        }

        return null;
    }

    /**
     * Get the label ZPL data.
     */
    public function getLabelZpl(ShippingLabel $label): ?string
    {
        return $label->label_zpl;
    }

    /**
     * Void a shipping label.
     */
    public function voidLabel(ShippingLabel $label): bool
    {
        if (! $label->tracking_number) {
            return false;
        }

        $fedExService = $this->getFedExService($label->store);
        $voided = $fedExService->voidShipment($label->tracking_number);

        if ($voided) {
            $label->update(['status' => ShippingLabel::STATUS_VOIDED]);
        }

        return $voided;
    }

    /**
     * Track a shipping label.
     *
     * @return array<string, mixed>|null
     */
    public function trackLabel(ShippingLabel $label): ?array
    {
        if (! $label->tracking_number) {
            return null;
        }

        $fedExService = $this->getFedExService($label->store);

        return $fedExService->trackShipment($label->tracking_number);
    }

    /**
     * Store the label PDF file.
     */
    protected function storeLabelPdf(string $base64Pdf, Transaction $transaction, string $type): string
    {
        $filename = sprintf(
            'shipping-labels/%s/%s-%s.pdf',
            $transaction->transaction_number,
            $type,
            now()->timestamp
        );

        Storage::put($filename, base64_decode($base64Pdf));

        return $filename;
    }

    /**
     * Format store address for shipping.
     *
     * @return array<string, mixed>
     */
    protected function formatStoreAddress(Store $store): array
    {
        // Convert state name to abbreviation if needed
        $stateCode = $store->state ?? '';
        if ($stateCode && strlen($stateCode) > 2) {
            // It's a full state name, look up the abbreviation
            $state = State::where('name', $stateCode)->first();
            $stateCode = $state?->abbreviation ?? $stateCode;
        }

        return [
            'name' => $store->business_name ?? $store->name,
            'company' => $store->business_name ?? $store->name,
            'street' => $store->address ?? '',
            'street2' => $store->address2,
            'city' => $store->city ?? '',
            'state' => $stateCode,
            'postal_code' => $store->zip ?? '',
            'country' => 'US',
            'phone' => $store->phone ?? '',
        ];
    }

    /**
     * Format customer address for shipping.
     *
     * @return array<string, mixed>
     */
    protected function formatCustomerAddress(Customer $customer): array
    {
        return [
            'name' => $customer->full_name,
            'company' => $customer->company_name ?? '',
            'street' => $customer->address ?? '',
            'street2' => $customer->address2,
            'city' => $customer->city ?? '',
            'state' => $customer->state?->abbreviation ?? '',
            'postal_code' => $customer->zip ?? '',
            'country' => $customer->country?->code ?? 'US',
            'phone' => $customer->phone_number ?? '',
        ];
    }

    /**
     * Get the recipient address for a transaction.
     * Priority: 1. Transaction's shipping address, 2. Customer's primary shipping address, 3. Customer's fields
     *
     * @return array<string, mixed>
     */
    protected function getTransactionRecipientAddress(Transaction $transaction): array
    {
        // 1. Check for a specific shipping address on the transaction
        if ($transaction->shippingAddress) {
            return $this->formatAddress($transaction->shippingAddress);
        }

        // 2. Check for customer's primary shipping address
        $customer = $transaction->customer;
        if ($customer) {
            $primaryAddress = $customer->getPrimaryShippingAddress();
            if ($primaryAddress && $primaryAddress->isValidForShipping()) {
                return $this->formatAddress($primaryAddress);
            }

            // 3. Fall back to customer's direct fields
            return $this->formatCustomerAddress($customer);
        }

        throw new InvalidArgumentException('Transaction must have a customer or shipping address.');
    }

    /**
     * Get the store's shipping address.
     * Priority: 1. Store's primary shipping address, 2. Store's fields
     *
     * @return array<string, mixed>
     */
    protected function getStoreShippingAddress(Store $store): array
    {
        // 1. Check for store's primary shipping address
        $primaryAddress = $store->getPrimaryShippingAddress();
        if ($primaryAddress && $primaryAddress->isValidForShipping()) {
            return $this->formatAddress($primaryAddress);
        }

        // 2. Fall back to store's direct fields
        return $this->formatStoreAddress($store);
    }

    /**
     * Format an Address model for shipping.
     *
     * @return array<string, mixed>
     */
    protected function formatAddress(Address $address): array
    {
        return $address->toShippingFormat();
    }

    /**
     * Build package details from options or use defaults.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function buildPackageDetails(array $options): array
    {
        $defaults = config('services.fedex.default_package', [
            'weight' => 1,
            'length' => 12,
            'width' => 12,
            'height' => 6,
        ]);

        return [
            'weight' => (float) ($options['weight'] ?? $defaults['weight']),
            'length' => (float) ($options['length'] ?? $defaults['length']),
            'width' => (float) ($options['width'] ?? $defaults['width']),
            'height' => (float) ($options['height'] ?? $defaults['height']),
        ];
    }

    /**
     * Get default package details for jewelry kits.
     *
     * @return array<string, mixed>
     */
    protected function getDefaultPackageDetails(): array
    {
        return $this->buildPackageDetails([]);
    }

    /**
     * Get available service types.
     *
     * @return array<string, string>
     */
    public static function getServiceTypes(): array
    {
        return config('logistics.fedex.service_types', [
            'FEDEX_2_DAY' => 'FedEx 2 Day',
            'FEDEX_EXPRESS_SAVER' => 'FedEx Express Saver',
            'FEDEX_GROUND' => 'FedEx Ground',
            'PRIORITY_OVERNIGHT' => 'Priority Overnight',
            'STANDARD_OVERNIGHT' => 'Standard Overnight',
        ]);
    }

    /**
     * Get available packaging types.
     *
     * @return array<string, string>
     */
    public static function getPackagingTypes(): array
    {
        return config('logistics.fedex.packaging_types', [
            'FEDEX_ENVELOPE' => 'FedEx Envelope',
            'FEDEX_PAK' => 'FedEx Pak',
            'FEDEX_BOX' => 'FedEx Box',
            'FEDEX_TUBE' => 'FedEx Tube',
            'YOUR_PACKAGING' => 'Your Packaging',
        ]);
    }

    /**
     * Get default package dimensions.
     *
     * @return array<string, mixed>
     */
    public static function getDefaultPackageDimensions(): array
    {
        return config('logistics.fedex.default_package', [
            'weight' => 1,
            'length' => 12,
            'width' => 12,
            'height' => 6,
        ]);
    }
}
