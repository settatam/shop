<?php

namespace App\Contracts;

use App\Models\PaymentTerminal;
use App\Services\Gateways\Results\CancelResult;
use App\Services\Gateways\Results\CheckoutResult;
use App\Services\Gateways\Results\CheckoutStatus;
use App\Services\Gateways\Results\PairResult;

interface TerminalGatewayInterface extends PaymentGatewayInterface
{
    public function createCheckout(PaymentTerminal $terminal, float $amount, array $options = []): CheckoutResult;

    public function getCheckoutStatus(string $checkoutId): CheckoutStatus;

    public function cancelCheckout(string $checkoutId): CancelResult;

    public function listDevices(string $locationId): array;

    public function pairDevice(string $deviceCode, array $options = []): PairResult;
}
