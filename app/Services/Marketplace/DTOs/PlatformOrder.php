<?php

namespace App\Services\Marketplace\DTOs;

use Carbon\Carbon;

class PlatformOrder
{
    public function __construct(
        public string $externalId,
        public ?string $orderNumber = null,
        public string $status = 'pending',
        public string $fulfillmentStatus = 'unfulfilled',
        public string $paymentStatus = 'pending',
        public float $total = 0.0,
        public float $subtotal = 0.0,
        public float $shippingCost = 0.0,
        public float $tax = 0.0,
        public float $discount = 0.0,
        public string $currency = 'USD',
        public array $customer = [],
        public array $shippingAddress = [],
        public array $billingAddress = [],
        public array $lineItems = [],
        public ?Carbon $orderedAt = null,
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            externalId: (string) ($data['external_id'] ?? $data['id'] ?? ''),
            orderNumber: $data['order_number'] ?? $data['name'] ?? null,
            status: $data['status'] ?? 'pending',
            fulfillmentStatus: $data['fulfillment_status'] ?? 'unfulfilled',
            paymentStatus: $data['payment_status'] ?? $data['financial_status'] ?? 'pending',
            total: (float) ($data['total'] ?? $data['total_price'] ?? 0),
            subtotal: (float) ($data['subtotal'] ?? $data['subtotal_price'] ?? 0),
            shippingCost: (float) ($data['shipping_cost'] ?? $data['total_shipping_price_set']['shop_money']['amount'] ?? 0),
            tax: (float) ($data['tax'] ?? $data['total_tax'] ?? 0),
            discount: (float) ($data['discount'] ?? $data['total_discounts'] ?? 0),
            currency: $data['currency'] ?? 'USD',
            customer: $data['customer'] ?? [],
            shippingAddress: $data['shipping_address'] ?? [],
            billingAddress: $data['billing_address'] ?? [],
            lineItems: $data['line_items'] ?? [],
            orderedAt: isset($data['ordered_at']) ? Carbon::parse($data['ordered_at']) : (isset($data['created_at']) ? Carbon::parse($data['created_at']) : null),
            metadata: $data['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'order_number' => $this->orderNumber,
            'status' => $this->status,
            'fulfillment_status' => $this->fulfillmentStatus,
            'payment_status' => $this->paymentStatus,
            'total' => $this->total,
            'subtotal' => $this->subtotal,
            'shipping_cost' => $this->shippingCost,
            'tax' => $this->tax,
            'discount' => $this->discount,
            'currency' => $this->currency,
            'customer' => $this->customer,
            'shipping_address' => $this->shippingAddress,
            'billing_address' => $this->billingAddress,
            'line_items' => $this->lineItems,
            'ordered_at' => $this->orderedAt?->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}
