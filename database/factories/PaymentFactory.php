<?php

namespace Database\Factories;

use App\Models\Memo;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Repair;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'order_id' => Order::factory(),
            'customer_id' => null,
            'user_id' => User::factory(),
            'payment_method' => fake()->randomElement([
                Payment::METHOD_CASH,
                Payment::METHOD_CARD,
                Payment::METHOD_CHECK,
            ]),
            'status' => Payment::STATUS_COMPLETED,
            'amount' => fake()->randomFloat(2, 10, 500),
            'currency' => 'USD',
            'paid_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Payment::STATUS_PENDING,
            'paid_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Payment::STATUS_COMPLETED,
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Payment::STATUS_FAILED,
            'paid_at' => null,
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Payment::STATUS_REFUNDED,
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => Payment::METHOD_CASH,
        ]);
    }

    public function card(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => Payment::METHOD_CARD,
            'gateway' => fake()->randomElement(['stripe', 'square']),
            'transaction_id' => fake()->uuid(),
        ]);
    }

    public function storeCredit(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => Payment::METHOD_STORE_CREDIT,
        ]);
    }

    public function layaway(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => Payment::METHOD_LAYAWAY,
        ]);
    }

    public function amount(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
        ]);
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => $order->store_id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
        ]);
    }

    public function forMemo(Memo $memo): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => $memo->store_id,
            'payable_type' => Memo::class,
            'payable_id' => $memo->id,
            'memo_id' => $memo->id,
            'order_id' => null,
        ]);
    }

    public function forRepair(Repair $repair): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => $repair->store_id,
            'payable_type' => Repair::class,
            'payable_id' => $repair->id,
            'order_id' => null,
        ]);
    }
}
