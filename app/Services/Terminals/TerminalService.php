<?php

namespace App\Services\Terminals;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentTerminal;
use App\Models\Store;
use App\Models\TerminalCheckout;
use App\Services\Gateways\PaymentGatewayFactory;
use App\Services\Gateways\Results\CheckoutStatus;
use App\Services\StoreContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class TerminalService
{
    public function __construct(
        protected PaymentGatewayFactory $gatewayFactory,
        protected StoreContext $storeContext,
    ) {}

    public function createCheckout(Invoice $invoice, PaymentTerminal $terminal, float $amount): TerminalCheckout
    {
        if (! $invoice->canAcceptPayment()) {
            throw new InvalidArgumentException('Invoice cannot accept payments in its current state.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Checkout amount must be greater than zero.');
        }

        if ($amount > $invoice->balance_due) {
            throw new InvalidArgumentException('Checkout amount cannot exceed balance due.');
        }

        if (! $terminal->isActive()) {
            throw new InvalidArgumentException('Terminal is not active.');
        }

        if ($invoice->store_id !== $terminal->store_id) {
            throw new InvalidArgumentException('Terminal does not belong to the same store as the invoice.');
        }

        $gateway = $this->gatewayFactory->makeTerminal($terminal->gateway);
        $timeout = config('payment-gateways.terminal.default_timeout', 300);

        $result = $gateway->createCheckout($terminal, $amount, [
            'timeout' => $timeout,
            'invoice_number' => $invoice->invoice_number,
            'customer_id' => $invoice->customer_id,
        ]);

        if (! $result->success) {
            throw new RuntimeException($result->errorMessage ?? 'Failed to create terminal checkout');
        }

        return TerminalCheckout::create([
            'store_id' => $terminal->store_id,
            'invoice_id' => $invoice->id,
            'terminal_id' => $terminal->id,
            'user_id' => auth()->id(),
            'checkout_id' => $result->checkoutId,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'status' => TerminalCheckout::STATUS_PENDING,
            'timeout_seconds' => $timeout,
            'expires_at' => $result->expiresAt ?? now()->addSeconds($timeout),
            'gateway_response' => $result->gatewayResponse,
        ]);
    }

    public function pollCheckoutStatus(TerminalCheckout $checkout): CheckoutStatus
    {
        if ($checkout->isTerminal()) {
            return new CheckoutStatus(
                checkoutId: $checkout->checkout_id,
                status: $this->mapCheckoutStatus($checkout->status),
                paymentId: $checkout->external_payment_id
            );
        }

        $terminal = $checkout->terminal;
        $gateway = $this->gatewayFactory->makeTerminal($terminal->gateway);

        $status = $gateway->getCheckoutStatus($checkout->checkout_id);

        // Update our local checkout status based on gateway response
        if ($status->isCompleted()) {
            $this->handleCheckoutCompleted($checkout, $status);
        } elseif ($status->isFailed()) {
            $checkout->markAsFailed($status->errorMessage ?? 'Payment failed');
        } elseif ($status->isCancelled()) {
            $checkout->cancel();
        }

        return $status;
    }

    public function cancelCheckout(TerminalCheckout $checkout): void
    {
        if ($checkout->isTerminal()) {
            throw new InvalidArgumentException('Cannot cancel a checkout that has already completed, failed, or been cancelled.');
        }

        $terminal = $checkout->terminal;
        $gateway = $this->gatewayFactory->makeTerminal($terminal->gateway);

        $result = $gateway->cancelCheckout($checkout->checkout_id);

        if (! $result->success) {
            throw new RuntimeException($result->errorMessage ?? 'Failed to cancel checkout');
        }

        $checkout->cancel();
    }

    public function handleTimeout(TerminalCheckout $checkout): void
    {
        if ($checkout->isTerminal()) {
            return;
        }

        if (! $checkout->isExpired()) {
            return;
        }

        // Try to cancel with the gateway first
        try {
            $terminal = $checkout->terminal;
            $gateway = $this->gatewayFactory->makeTerminal($terminal->gateway);
            $gateway->cancelCheckout($checkout->checkout_id);
        } catch (\Exception $e) {
            // Log but don't fail - mark as timeout anyway
        }

        $checkout->markAsTimeout();
    }

    public function pairTerminal(Store $store, string $gateway, string $deviceCode, array $options = []): PaymentTerminal
    {
        if (! $this->gatewayFactory->supportsTerminal($gateway)) {
            throw new InvalidArgumentException("Unsupported terminal gateway: {$gateway}");
        }

        $gatewayInstance = $this->gatewayFactory->makeTerminal($gateway);

        $result = $gatewayInstance->pairDevice($deviceCode, $options);

        if (! $result->success) {
            throw new RuntimeException($result->errorMessage ?? 'Failed to pair terminal');
        }

        return PaymentTerminal::create([
            'store_id' => $store->id,
            'name' => $options['name'] ?? $result->deviceName ?? 'Payment Terminal',
            'gateway' => $gateway,
            'device_id' => $result->deviceId,
            'device_code' => $deviceCode,
            'location_id' => $options['location_id'] ?? null,
            'status' => PaymentTerminal::STATUS_ACTIVE,
            'capabilities' => $result->capabilities,
            'paired_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function listAvailableDevices(Store $store, string $gateway): array
    {
        if (! $this->gatewayFactory->supportsTerminal($gateway)) {
            throw new InvalidArgumentException("Unsupported terminal gateway: {$gateway}");
        }

        $gatewayInstance = $this->gatewayFactory->makeTerminal($gateway);

        // For Square, we'd need the location_id from the store settings
        $locationId = $store->settings['square_location_id'] ?? '';

        return $gatewayInstance->listDevices($locationId);
    }

    protected function handleCheckoutCompleted(TerminalCheckout $checkout, CheckoutStatus $status): void
    {
        DB::transaction(function () use ($checkout, $status) {
            $checkout->markAsCompleted($status->paymentId);

            // Create the payment record
            $payment = Payment::create([
                'store_id' => $checkout->store_id,
                'invoice_id' => $checkout->invoice_id,
                'terminal_checkout_id' => $checkout->id,
                'customer_id' => $checkout->invoice?->customer_id,
                'user_id' => $checkout->user_id,
                'payment_method' => Payment::METHOD_CARD,
                'status' => Payment::STATUS_COMPLETED,
                'amount' => $checkout->amount,
                'currency' => $checkout->currency,
                'gateway' => $checkout->terminal->gateway,
                'gateway_payment_id' => $status->paymentId,
                'gateway_response' => $status->gatewayResponse,
                'metadata' => [
                    'card_brand' => $status->cardBrand,
                    'card_last_four' => $status->cardLastFour,
                ],
                'paid_at' => now(),
            ]);

            // Update checkout with payment reference
            $checkout->update(['payment_id' => $payment->id]);

            // Recalculate invoice totals
            if ($checkout->invoice) {
                $checkout->invoice->recalculateTotals();
            }
        });
    }

    protected function mapCheckoutStatus(string $status): string
    {
        return match ($status) {
            TerminalCheckout::STATUS_PENDING => CheckoutStatus::STATUS_PENDING,
            TerminalCheckout::STATUS_PROCESSING => CheckoutStatus::STATUS_IN_PROGRESS,
            TerminalCheckout::STATUS_COMPLETED => CheckoutStatus::STATUS_COMPLETED,
            TerminalCheckout::STATUS_FAILED => CheckoutStatus::STATUS_FAILED,
            TerminalCheckout::STATUS_CANCELLED => CheckoutStatus::STATUS_CANCELLED,
            TerminalCheckout::STATUS_TIMEOUT => CheckoutStatus::STATUS_TIMEOUT,
            default => CheckoutStatus::STATUS_PENDING,
        };
    }
}
