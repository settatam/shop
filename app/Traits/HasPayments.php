<?php

namespace App\Traits;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Provides common payment functionality for payable models.
 * Models using this trait should implement the Payable interface.
 */
trait HasPayments
{
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function getStoreId(): int
    {
        return (int) $this->store_id;
    }

    public function getSubtotal(): float
    {
        return (float) $this->total;
    }

    public function getGrandTotal(): float
    {
        return (float) ($this->grand_total ?? $this->total);
    }

    public function getTotalPaid(): float
    {
        return (float) ($this->total_paid ?? 0);
    }

    public function getBalanceDue(): float
    {
        return (float) ($this->balance_due ?? $this->getGrandTotal() - $this->getTotalPaid());
    }

    public function isFullyPaid(): bool
    {
        return $this->getBalanceDue() <= 0.01;
    }

    public function hasPayments(): bool
    {
        return $this->payments()->exists();
    }

    public function recordPayment(float $amount): void
    {
        $newTotalPaid = $this->getTotalPaid() + $amount;
        $newBalanceDue = max(0, $this->getGrandTotal() - $newTotalPaid);

        $this->update([
            'total_paid' => $newTotalPaid,
            'balance_due' => $newBalanceDue,
        ]);
    }

    public function getPaymentAdjustments(): array
    {
        return [
            'discount_value' => (float) ($this->discount_value ?? 0),
            'discount_unit' => $this->discount_unit ?? 'fixed',
            'discount_reason' => $this->discount_reason,
            'service_fee_value' => (float) ($this->service_fee_value ?? 0),
            'service_fee_unit' => $this->service_fee_unit ?? 'fixed',
            'service_fee_reason' => $this->service_fee_reason,
            'charge_taxes' => (bool) ($this->charge_taxes ?? false),
            'tax_rate' => (float) ($this->tax_rate ?? 0),
            'tax_type' => $this->tax_type ?? 'percent',
            'shipping_cost' => (float) ($this->shipping_cost ?? 0),
        ];
    }

    public function updatePaymentAdjustments(array $adjustments): void
    {
        $this->update([
            'discount_value' => $adjustments['discount_value'] ?? $this->discount_value,
            'discount_unit' => $adjustments['discount_unit'] ?? $this->discount_unit,
            'discount_reason' => $adjustments['discount_reason'] ?? $this->discount_reason,
            'service_fee_value' => $adjustments['service_fee_value'] ?? $this->service_fee_value,
            'service_fee_unit' => $adjustments['service_fee_unit'] ?? $this->service_fee_unit,
            'service_fee_reason' => $adjustments['service_fee_reason'] ?? $this->service_fee_reason,
            'charge_taxes' => $adjustments['charge_taxes'] ?? $this->charge_taxes,
            'tax_rate' => $adjustments['tax_rate'] ?? $this->tax_rate,
            'tax_type' => $adjustments['tax_type'] ?? $this->tax_type,
            'shipping_cost' => $adjustments['shipping_cost'] ?? $this->shipping_cost,
        ]);
    }

    public function updateCalculatedTotals(array $summary): void
    {
        $this->update([
            'discount_amount' => $summary['discount_amount'],
            'service_fee_amount' => $summary['service_fee_amount'],
            'tax_amount' => $summary['tax_amount'],
            'grand_total' => $summary['grand_total'],
            'balance_due' => $summary['balance_due'],
        ]);
    }
}
