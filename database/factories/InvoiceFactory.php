<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 50, 500);
        $tax = $subtotal * 0.08;
        $shipping = fake()->optional(0.3)->randomFloat(2, 5, 25) ?? 0;
        $discount = fake()->optional(0.2)->randomFloat(2, 5, 30) ?? 0;
        $total = max(0, $subtotal + $tax + $shipping - $discount);

        return [
            'store_id' => Store::factory(),
            'customer_id' => Customer::factory(),
            'user_id' => User::factory(),
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'invoiceable_type' => Order::class,
            'invoiceable_id' => Order::factory(),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'shipping' => $shipping,
            'discount' => $discount,
            'total' => $total,
            'total_paid' => 0,
            'balance_due' => $total,
            'status' => Invoice::STATUS_PENDING,
            'currency' => 'USD',
            'due_date' => now()->addDays(30),
            'paid_at' => null,
            'notes' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_DRAFT,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_PENDING,
        ]);
    }

    public function partial(): static
    {
        return $this->state(function (array $attributes) {
            $total = $attributes['total'];
            $paid = $total * 0.5;

            return [
                'status' => Invoice::STATUS_PARTIAL,
                'total_paid' => $paid,
                'balance_due' => $total - $paid,
            ];
        });
    }

    public function paid(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => Invoice::STATUS_PAID,
                'total_paid' => $attributes['total'],
                'balance_due' => 0,
                'paid_at' => now(),
            ];
        });
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_OVERDUE,
            'due_date' => now()->subDays(7),
        ]);
    }

    public function void(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_VOID,
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_REFUNDED,
        ]);
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes) => [
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $order->id,
            'store_id' => $order->store_id,
            'customer_id' => $order->customer_id,
            'subtotal' => $order->sub_total,
            'tax' => $order->sales_tax,
            'shipping' => $order->shipping_cost,
            'discount' => $order->discount_cost,
            'total' => $order->total,
            'balance_due' => $order->total,
        ]);
    }

    public function withCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => $customer->id,
        ]);
    }
}
