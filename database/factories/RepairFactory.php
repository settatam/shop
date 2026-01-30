<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Repair;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Repair>
 */
class RepairFactory extends Factory
{
    protected $model = Repair::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 50, 300);
        $serviceFee = fake()->optional(0.3)->randomFloat(2, 10, 50) ?? 0;
        $taxRate = 0.08;
        $tax = $subtotal * $taxRate;
        $shipping = fake()->optional(0.2)->randomFloat(2, 5, 20) ?? 0;
        $discount = fake()->optional(0.1)->randomFloat(2, 5, 20) ?? 0;

        return [
            'store_id' => Store::factory(),
            'customer_id' => Customer::factory(),
            'vendor_id' => Customer::factory(),
            'user_id' => User::factory(),
            'order_id' => null,
            'repair_number' => 'REP-TEMP', // Will be updated in model booted event
            'status' => Repair::STATUS_PENDING,
            'service_fee' => $serviceFee,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'tax_rate' => $taxRate,
            'discount' => $discount,
            'shipping_cost' => $shipping,
            'total' => $subtotal + $serviceFee + $tax + $shipping - $discount,
            'description' => fake()->optional()->sentence(),
            'repair_days' => 0,
            'is_appraisal' => false,
            'date_sent_to_vendor' => null,
            'date_received_by_vendor' => null,
            'date_completed' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Repair::STATUS_PENDING,
        ]);
    }

    public function sentToVendor(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Repair::STATUS_SENT_TO_VENDOR,
            'date_sent_to_vendor' => now(),
        ]);
    }

    public function receivedByVendor(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Repair::STATUS_RECEIVED_BY_VENDOR,
            'date_sent_to_vendor' => now()->subDays(2),
            'date_received_by_vendor' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Repair::STATUS_COMPLETED,
            'date_sent_to_vendor' => now()->subDays(5),
            'date_received_by_vendor' => now()->subDays(3),
            'date_completed' => now(),
            'repair_days' => 3,
        ]);
    }

    public function paymentReceived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Repair::STATUS_PAYMENT_RECEIVED,
            'date_sent_to_vendor' => now()->subDays(7),
            'date_received_by_vendor' => now()->subDays(5),
            'date_completed' => now()->subDays(2),
            'repair_days' => 3,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Repair::STATUS_CANCELLED,
        ]);
    }

    public function appraisal(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_appraisal' => true,
        ]);
    }

    public function withVendor(Customer $vendor): static
    {
        return $this->state(fn (array $attributes) => [
            'vendor_id' => $vendor->id,
        ]);
    }

    public function withCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => $customer->id,
        ]);
    }

    public function withWarehouse(Warehouse $warehouse): static
    {
        return $this->state(fn (array $attributes) => [
            'warehouse_id' => $warehouse->id,
        ]);
    }
}
