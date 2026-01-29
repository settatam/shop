<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchaseOrderItem>
 */
class PurchaseOrderItemFactory extends Factory
{
    protected $model = PurchaseOrderItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantityOrdered = fake()->numberBetween(1, 100);
        $unitCost = fake()->randomFloat(2, 10, 500);
        $discountPercent = fake()->randomElement([0, 0, 0, 5, 10, 15]);
        $taxRate = fake()->randomElement([0, 7, 8.25, 10]);

        $subtotal = $quantityOrdered * $unitCost;
        $discount = $subtotal * ($discountPercent / 100);
        $afterDiscount = $subtotal - $discount;
        $tax = $afterDiscount * ($taxRate / 100);
        $lineTotal = $afterDiscount + $tax;

        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'vendor_sku' => fake()->optional()->bothify('VND-SKU-####'),
            'description' => fake()->optional()->sentence(4),
            'quantity_ordered' => $quantityOrdered,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
            'discount_percent' => $discountPercent,
            'tax_rate' => $taxRate,
            'line_total' => $lineTotal,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function partiallyReceived(?int $receivedQty = null): static
    {
        return $this->state(function (array $attributes) use ($receivedQty) {
            $ordered = $attributes['quantity_ordered'] ?? 10;
            $received = $receivedQty ?? fake()->numberBetween(1, $ordered - 1);

            return [
                'quantity_received' => $received,
            ];
        });
    }

    public function fullyReceived(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'quantity_received' => $attributes['quantity_ordered'] ?? 10,
            ];
        });
    }

    public function withQuantity(int $quantity): static
    {
        return $this->state(fn () => [
            'quantity_ordered' => $quantity,
        ]);
    }

    public function withCost(float $cost): static
    {
        return $this->state(fn () => [
            'unit_cost' => $cost,
        ]);
    }
}
