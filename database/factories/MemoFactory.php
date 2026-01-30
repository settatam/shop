<?php

namespace Database\Factories;

use App\Models\Memo;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Memo>
 */
class MemoFactory extends Factory
{
    protected $model = Memo::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 1000);
        $taxRate = 0.08;
        $chargeTaxes = fake()->boolean(80);
        $tax = $chargeTaxes ? $subtotal * $taxRate : 0;
        $shipping = fake()->optional(0.2)->randomFloat(2, 10, 50) ?? 0;

        return [
            'store_id' => Store::factory(),
            'vendor_id' => Vendor::factory(),
            'user_id' => User::factory(),
            'order_id' => null,
            'memo_number' => 'MEM-TEMP', // Will be updated in model booted event
            'status' => Memo::STATUS_PENDING,
            'tenure' => fake()->randomElement(Memo::PAYMENT_TERMS),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'tax_rate' => $taxRate,
            'charge_taxes' => $chargeTaxes,
            'shipping_cost' => $shipping,
            'total' => $subtotal + $tax + $shipping,
            'description' => fake()->optional()->sentence(),
            'duration' => 0,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Memo::STATUS_PENDING,
        ]);
    }

    public function sentToVendor(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Memo::STATUS_SENT_TO_VENDOR,
        ]);
    }

    public function vendorReceived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Memo::STATUS_VENDOR_RECEIVED,
        ]);
    }

    public function vendorReturned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Memo::STATUS_VENDOR_RETURNED,
            'duration' => fake()->numberBetween(5, 30),
        ]);
    }

    public function paymentReceived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Memo::STATUS_PAYMENT_RECEIVED,
            'duration' => fake()->numberBetween(5, 60),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Memo::STATUS_ARCHIVED,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Memo::STATUS_CANCELLED,
        ]);
    }

    public function withTenure(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'tenure' => $days,
        ]);
    }

    public function withVendor(Vendor $vendor): static
    {
        return $this->state(fn (array $attributes) => [
            'vendor_id' => $vendor->id,
        ]);
    }

    public function noTax(): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_taxes' => false,
            'tax' => 0,
            'total' => $attributes['subtotal'] + ($attributes['shipping_cost'] ?? 0),
        ]);
    }

    public function withWarehouse(Warehouse $warehouse): static
    {
        return $this->state(fn (array $attributes) => [
            'warehouse_id' => $warehouse->id,
        ]);
    }
}
