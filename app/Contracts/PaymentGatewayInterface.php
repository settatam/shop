<?php

namespace App\Contracts;

use App\Services\Gateways\Results\PaymentResult;
use App\Services\Gateways\Results\RefundResult;
use App\Services\Gateways\Results\VoidResult;

interface PaymentGatewayInterface
{
    public function charge(float $amount, array $paymentMethod, array $options = []): PaymentResult;

    public function refund(string $paymentId, float $amount): RefundResult;

    public function void(string $paymentId): VoidResult;

    public function getPayment(string $paymentId): ?array;
}
