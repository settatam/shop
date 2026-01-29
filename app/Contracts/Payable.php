<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Payable
{
    /**
     * Get the store ID this payable belongs to.
     */
    public function getStoreId(): int;

    /**
     * Get the subtotal/base amount for the payable.
     */
    public function getSubtotal(): float;

    /**
     * Get the grand total including all adjustments.
     */
    public function getGrandTotal(): float;

    /**
     * Get the total amount paid so far.
     */
    public function getTotalPaid(): float;

    /**
     * Get the remaining balance due.
     */
    public function getBalanceDue(): float;

    /**
     * Check if the payable can receive payment.
     */
    public function canReceivePayment(): bool;

    /**
     * Check if the payable is fully paid.
     */
    public function isFullyPaid(): bool;

    /**
     * Record a payment amount.
     */
    public function recordPayment(float $amount): void;

    /**
     * Get all payments for this payable.
     */
    public function payments(): MorphMany;

    /**
     * Get the display identifier (e.g., memo number, repair number).
     */
    public function getDisplayIdentifier(): string;

    /**
     * Get the payable type name for display.
     */
    public static function getPayableTypeName(): string;

    /**
     * Called when payment is fully completed.
     */
    public function onPaymentComplete(): void;

    /**
     * Get adjustment values for payment calculation.
     *
     * @return array{
     *     discount_value: float,
     *     discount_unit: string,
     *     discount_reason: string|null,
     *     service_fee_value: float,
     *     service_fee_unit: string,
     *     service_fee_reason: string|null,
     *     charge_taxes: bool,
     *     tax_rate: float,
     *     tax_type: string,
     *     shipping_cost: float
     * }
     */
    public function getPaymentAdjustments(): array;

    /**
     * Update payment adjustments.
     */
    public function updatePaymentAdjustments(array $adjustments): void;

    /**
     * Update calculated totals after adjustments change.
     */
    public function updateCalculatedTotals(array $summary): void;
}
