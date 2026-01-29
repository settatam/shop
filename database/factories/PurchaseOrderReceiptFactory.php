<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchaseOrderReceipt>
 */
class PurchaseOrderReceiptFactory extends Factory
{
    protected $model = PurchaseOrderReceipt::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'purchase_order_id' => PurchaseOrder::factory(),
            'received_by' => User::factory(),
            'received_at' => fake()->dateTimeBetween('-1 week', 'now'),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forPurchaseOrder(PurchaseOrder $po): static
    {
        return $this->state(fn () => [
            'store_id' => $po->store_id,
            'purchase_order_id' => $po->id,
        ]);
    }
}
