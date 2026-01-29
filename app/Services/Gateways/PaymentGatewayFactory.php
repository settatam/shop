<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\TerminalGatewayInterface;
use InvalidArgumentException;

class PaymentGatewayFactory
{
    /**
     * @var array<string, class-string<PaymentGatewayInterface>>
     */
    protected array $gateways = [
        'square' => SquareTerminalGateway::class,
        'dejavoo' => DejavooTerminalGateway::class,
    ];

    /**
     * @var array<string, class-string<TerminalGatewayInterface>>
     */
    protected array $terminalGateways = [
        'square' => SquareTerminalGateway::class,
        'dejavoo' => DejavooTerminalGateway::class,
    ];

    public function make(string $gateway): PaymentGatewayInterface
    {
        if (! isset($this->gateways[$gateway])) {
            throw new InvalidArgumentException("Unsupported payment gateway: {$gateway}");
        }

        return app($this->gateways[$gateway]);
    }

    public function makeTerminal(string $gateway): TerminalGatewayInterface
    {
        if (! isset($this->terminalGateways[$gateway])) {
            throw new InvalidArgumentException("Unsupported terminal gateway: {$gateway}");
        }

        return app($this->terminalGateways[$gateway]);
    }

    public function supports(string $gateway): bool
    {
        return isset($this->gateways[$gateway]);
    }

    public function supportsTerminal(string $gateway): bool
    {
        return isset($this->terminalGateways[$gateway]);
    }

    /**
     * @return array<string>
     */
    public function availableGateways(): array
    {
        return array_keys($this->gateways);
    }

    /**
     * @return array<string>
     */
    public function availableTerminalGateways(): array
    {
        return array_keys($this->terminalGateways);
    }
}
