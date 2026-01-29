<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductReturn;
use App\Models\ReturnPolicy;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductReturn>
 */
class ProductReturnFactory extends Factory
{
    protected $model = ProductReturn::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 20, 300);
        $restockingFee = fake()->optional(0.2)->randomFloat(2, 5, 30) ?? 0;

        return [
            'store_id' => Store::factory(),
            'order_id' => Order::factory(),
            'customer_id' => Customer::factory(),
            'return_policy_id' => null,
            'processed_by' => null,
            'return_number' => ProductReturn::generateReturnNumber(),
            'status' => ProductReturn::STATUS_PENDING,
            'type' => ProductReturn::TYPE_RETURN,
            'subtotal' => $subtotal,
            'restocking_fee' => $restockingFee,
            'refund_amount' => $subtotal - $restockingFee,
            'refund_method' => null,
            'store_credit_id' => null,
            'reason' => fake()->randomElement(['defective', 'wrong_item', 'not_as_described', 'changed_mind', 'too_small', 'too_large']),
            'customer_notes' => fake()->optional()->sentence(),
            'internal_notes' => null,
            'external_return_id' => null,
            'source_platform' => null,
            'store_marketplace_id' => null,
            'synced_at' => null,
            'sync_status' => null,
            'requested_at' => now(),
            'approved_at' => null,
            'received_at' => null,
            'completed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductReturn::STATUS_PENDING,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductReturn::STATUS_APPROVED,
            'approved_at' => now(),
            'processed_by' => User::factory(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductReturn::STATUS_PROCESSING,
            'approved_at' => now()->subHours(2),
            'processed_by' => User::factory(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductReturn::STATUS_COMPLETED,
            'approved_at' => now()->subDays(2),
            'completed_at' => now(),
            'processed_by' => User::factory(),
            'refund_method' => fake()->randomElement([
                ProductReturn::REFUND_ORIGINAL,
                ProductReturn::REFUND_STORE_CREDIT,
                ProductReturn::REFUND_CASH,
            ]),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductReturn::STATUS_REJECTED,
            'internal_notes' => 'Return rejected: '.fake()->sentence(),
            'processed_by' => User::factory(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductReturn::STATUS_CANCELLED,
        ]);
    }

    public function exchange(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductReturn::TYPE_EXCHANGE,
        ]);
    }

    public function withPolicy(): static
    {
        return $this->state(fn (array $attributes) => [
            'return_policy_id' => ReturnPolicy::factory(),
        ]);
    }

    public function fromPlatform(string $platform): static
    {
        return $this->state(fn (array $attributes) => [
            'source_platform' => $platform,
            'external_return_id' => fake()->uuid(),
            'store_marketplace_id' => StoreMarketplace::factory()->state(['platform' => $platform]),
        ]);
    }

    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'sync_status' => ProductReturn::SYNC_STATUS_SYNCED,
            'synced_at' => now(),
        ]);
    }

    public function syncPending(): static
    {
        return $this->state(fn (array $attributes) => [
            'sync_status' => ProductReturn::SYNC_STATUS_PENDING,
        ]);
    }

    public function syncFailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'sync_status' => ProductReturn::SYNC_STATUS_FAILED,
        ]);
    }

    public function received(): static
    {
        return $this->state(fn (array $attributes) => [
            'received_at' => now(),
        ]);
    }

    public function withRefund(string $method = ProductReturn::REFUND_ORIGINAL): static
    {
        return $this->state(fn (array $attributes) => [
            'refund_method' => $method,
        ]);
    }
}
