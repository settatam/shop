<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Layaway>
 */
class LayawayFactory extends Factory
{
    protected $model = Layaway::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 200, 2000);
        $taxRate = 0.08;
        $taxAmount = $subtotal * $taxRate;
        $total = $subtotal + $taxAmount;
        $termDays = fake()->randomElement(Layaway::TERM_OPTIONS);

        return [
            'store_id' => Store::factory(),
            'warehouse_id' => null,
            'customer_id' => Customer::factory(),
            'user_id' => User::factory(),
            'order_id' => null,
            'layaway_number' => 'LAY-TEMP',
            'status' => Layaway::STATUS_PENDING,
            'payment_type' => Layaway::PAYMENT_TYPE_FLEXIBLE,
            'term_days' => $termDays,
            'minimum_deposit_percent' => 10.00,
            'cancellation_fee_percent' => 10.00,
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'deposit_amount' => 0,
            'total_paid' => 0,
            'balance_due' => $total,
            'start_date' => now(),
            'due_date' => now()->addDays($termDays),
            'admin_notes' => fake()->optional()->sentence(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Layaway::STATUS_PENDING,
        ]);
    }

    public function active(): static
    {
        $depositPercent = 10;

        return $this->state(function (array $attributes) use ($depositPercent) {
            $depositAmount = $attributes['total'] * ($depositPercent / 100);

            return [
                'status' => Layaway::STATUS_ACTIVE,
                'deposit_amount' => $depositAmount,
                'total_paid' => $depositAmount,
                'balance_due' => $attributes['total'] - $depositAmount,
            ];
        });
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Layaway::STATUS_COMPLETED,
            'total_paid' => $attributes['total'],
            'balance_due' => 0,
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Layaway::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function defaulted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Layaway::STATUS_DEFAULTED,
        ]);
    }

    public function flexible(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_type' => Layaway::PAYMENT_TYPE_FLEXIBLE,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_type' => Layaway::PAYMENT_TYPE_SCHEDULED,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Layaway::STATUS_ACTIVE,
            'due_date' => now()->subDays(7),
        ]);
    }

    public function withTerm(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'term_days' => $days,
            'due_date' => now()->addDays($days),
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

    public function withPayment(float $amount): static
    {
        return $this->state(function (array $attributes) use ($amount) {
            return [
                'total_paid' => $amount,
                'balance_due' => $attributes['total'] - $amount,
            ];
        });
    }

    public function noTax(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'tax_rate' => 0,
                'tax_amount' => 0,
                'total' => $attributes['subtotal'],
                'balance_due' => $attributes['subtotal'],
            ];
        });
    }

    public function withTotal(float $total): static
    {
        $taxRate = 0.08;
        $subtotal = $total / (1 + $taxRate);
        $taxAmount = $total - $subtotal;

        return $this->state(fn (array $attributes) => [
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $total,
        ]);
    }
}
