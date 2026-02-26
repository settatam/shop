<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Store;
use App\Models\StoreCredit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StoreCredit>
 */
class StoreCreditFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'customer_id' => Customer::factory(),
            'type' => StoreCredit::TYPE_CREDIT,
            'amount' => fake()->randomFloat(2, 10, 500),
            'balance_after' => fake()->randomFloat(2, 10, 500),
            'source' => StoreCredit::SOURCE_BUY_TRANSACTION,
            'description' => fake()->sentence(),
        ];
    }

    public function credit(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StoreCredit::TYPE_CREDIT,
        ]);
    }

    public function debit(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StoreCredit::TYPE_DEBIT,
        ]);
    }

    public function fromBuyTransaction(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => StoreCredit::SOURCE_BUY_TRANSACTION,
        ]);
    }

    public function fromOrderPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StoreCredit::TYPE_DEBIT,
            'source' => StoreCredit::SOURCE_ORDER_PAYMENT,
        ]);
    }

    public function cashOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StoreCredit::TYPE_DEBIT,
            'source' => StoreCredit::SOURCE_CASH_OUT,
        ]);
    }
}
