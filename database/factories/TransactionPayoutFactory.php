<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionPayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TransactionPayout>
 */
class TransactionPayoutFactory extends Factory
{
    protected $model = TransactionPayout::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'transaction_id' => Transaction::factory(),
            'user_id' => null,
            'provider' => TransactionPayout::PROVIDER_PAYPAL,
            'payout_batch_id' => null,
            'payout_item_id' => null,
            'transaction_id_external' => null,
            'recipient_type' => TransactionPayout::RECIPIENT_TYPE_EMAIL,
            'recipient_value' => fake()->safeEmail(),
            'recipient_wallet' => TransactionPayout::WALLET_PAYPAL,
            'amount' => fake()->randomFloat(2, 10, 1000),
            'currency' => 'USD',
            'status' => TransactionPayout::STATUS_PENDING,
            'error_code' => null,
            'error_message' => null,
            'api_response' => null,
            'processed_at' => null,
        ];
    }

    public function paypal(): static
    {
        return $this->state(fn (array $attributes) => [
            'recipient_wallet' => TransactionPayout::WALLET_PAYPAL,
        ]);
    }

    public function venmo(): static
    {
        return $this->state(fn (array $attributes) => [
            'recipient_wallet' => TransactionPayout::WALLET_VENMO,
            'recipient_type' => TransactionPayout::RECIPIENT_TYPE_EMAIL,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionPayout::STATUS_PENDING,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionPayout::STATUS_PROCESSING,
            'payout_batch_id' => fake()->uuid(),
        ]);
    }

    public function success(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionPayout::STATUS_SUCCESS,
            'payout_batch_id' => fake()->uuid(),
            'payout_item_id' => fake()->uuid(),
            'transaction_id_external' => fake()->uuid(),
            'processed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionPayout::STATUS_FAILED,
            'error_code' => 'INSUFFICIENT_FUNDS',
            'error_message' => 'The sender does not have enough funds to cover the payout.',
            'processed_at' => now(),
        ]);
    }

    public function unclaimed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionPayout::STATUS_UNCLAIMED,
            'payout_batch_id' => fake()->uuid(),
            'payout_item_id' => fake()->uuid(),
            'processed_at' => now(),
        ]);
    }
}
