<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\PaymentTerminal;
use App\Models\Store;
use App\Models\TerminalCheckout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TerminalCheckout>
 */
class TerminalCheckoutFactory extends Factory
{
    protected $model = TerminalCheckout::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'invoice_id' => Invoice::factory(),
            'terminal_id' => PaymentTerminal::factory(),
            'user_id' => User::factory(),
            'checkout_id' => 'chk_'.fake()->uuid(),
            'amount' => fake()->randomFloat(2, 10, 500),
            'currency' => 'USD',
            'status' => TerminalCheckout::STATUS_PENDING,
            'external_payment_id' => null,
            'error_message' => null,
            'gateway_response' => null,
            'timeout_seconds' => TerminalCheckout::DEFAULT_TIMEOUT_SECONDS,
            'expires_at' => now()->addSeconds(TerminalCheckout::DEFAULT_TIMEOUT_SECONDS),
            'completed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TerminalCheckout::STATUS_PENDING,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TerminalCheckout::STATUS_PROCESSING,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TerminalCheckout::STATUS_COMPLETED,
            'external_payment_id' => 'pay_'.fake()->uuid(),
            'completed_at' => now(),
            'gateway_response' => [
                'status' => 'completed',
                'card_brand' => 'Visa',
                'last_4' => '4242',
            ],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TerminalCheckout::STATUS_FAILED,
            'error_message' => 'Card declined.',
            'gateway_response' => ['error' => 'card_declined'],
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TerminalCheckout::STATUS_CANCELLED,
        ]);
    }

    public function timeout(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TerminalCheckout::STATUS_TIMEOUT,
            'error_message' => 'Checkout timed out waiting for customer payment.',
            'expires_at' => now()->subMinutes(5),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinutes(1),
        ]);
    }

    public function forInvoice(Invoice $invoice): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_id' => $invoice->id,
            'store_id' => $invoice->store_id,
            'amount' => $invoice->balance_due,
        ]);
    }

    public function forTerminal(PaymentTerminal $terminal): static
    {
        return $this->state(fn (array $attributes) => [
            'terminal_id' => $terminal->id,
            'store_id' => $terminal->store_id,
        ]);
    }
}
