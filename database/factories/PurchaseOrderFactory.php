<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 10000);
        $taxAmount = $subtotal * 0.1;
        $shippingCost = fake()->randomFloat(2, 0, 100);
        $discountAmount = fake()->randomFloat(2, 0, $subtotal * 0.1);

        return [
            'store_id' => Store::factory(),
            'vendor_id' => Vendor::factory(),
            'warehouse_id' => Warehouse::factory(),
            'created_by' => User::factory(),
            'approved_by' => null,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'shipping_cost' => $shippingCost,
            'discount_amount' => $discountAmount,
            'total' => $subtotal + $taxAmount + $shippingCost - $discountAmount,
            'order_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'expected_date' => fake()->dateTimeBetween('now', '+1 month'),
            'shipping_method' => fake()->optional()->randomElement(['Ground', 'Express', 'Freight', 'Air']),
            'vendor_notes' => fake()->optional()->sentence(),
            'internal_notes' => fake()->optional()->sentence(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => PurchaseOrder::STATUS_DRAFT,
            'submitted_at' => null,
            'approved_at' => null,
            'approved_by' => null,
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => PurchaseOrder::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'approved_at' => null,
            'approved_by' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PurchaseOrder::STATUS_APPROVED,
            'submitted_at' => now()->subDay(),
            'approved_at' => now(),
            'approved_by' => User::factory(),
        ]);
    }

    public function partial(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PurchaseOrder::STATUS_PARTIAL,
            'submitted_at' => now()->subDays(2),
            'approved_at' => now()->subDay(),
            'approved_by' => User::factory(),
        ]);
    }

    public function received(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'submitted_at' => now()->subDays(3),
            'approved_at' => now()->subDays(2),
            'approved_by' => User::factory(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PurchaseOrder::STATUS_CLOSED,
            'submitted_at' => now()->subDays(4),
            'approved_at' => now()->subDays(3),
            'approved_by' => User::factory(),
            'closed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function forStore(Store $store): static
    {
        return $this->state(fn () => [
            'store_id' => $store->id,
            'vendor_id' => Vendor::factory()->for($store),
            'warehouse_id' => Warehouse::factory()->for($store),
        ]);
    }
}
