<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $subTotal = fake()->randomFloat(2, 20, 500);
        $shippingCost = fake()->randomFloat(2, 0, 25);
        $salesTax = $subTotal * 0.08;
        $discountCost = fake()->optional(0.3)->randomFloat(2, 5, 50) ?? 0;
        $total = $subTotal + $shippingCost + $salesTax - $discountCost;

        return [
            'store_id' => Store::factory(),
            'customer_id' => Customer::factory(),
            'user_id' => User::factory(),
            'status' => Order::STATUS_PENDING,
            'sub_total' => $subTotal,
            'shipping_cost' => $shippingCost,
            'sales_tax' => $salesTax,
            'tax_rate' => 0.08,
            'discount_cost' => $discountCost,
            'total' => max(0, $total),
            'date_of_purchase' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Order::STATUS_PENDING,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Order::STATUS_CONFIRMED,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Order::STATUS_PROCESSING,
        ]);
    }

    public function shipped(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Order::STATUS_SHIPPED,
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Order::STATUS_DELIVERED,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Order::STATUS_COMPLETED,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Order::STATUS_CANCELLED,
        ]);
    }

    public function partialPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Order::STATUS_PARTIAL_PAYMENT,
        ]);
    }

    public function fromPlatform(string $platform): static
    {
        return $this->state(fn (array $attributes) => [
            'source_platform' => $platform,
            'external_marketplace_id' => fake()->uuid(),
        ]);
    }

    public function withAddresses(): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_address' => [
                'address_line1' => fake()->streetAddress(),
                'city' => fake()->city(),
                'state' => fake()->stateAbbr(),
                'postal_code' => fake()->postcode(),
                'country' => 'US',
            ],
            'shipping_address' => [
                'address_line1' => fake()->streetAddress(),
                'city' => fake()->city(),
                'state' => fake()->stateAbbr(),
                'postal_code' => fake()->postcode(),
                'country' => 'US',
            ],
        ]);
    }

    public function withNotes(): static
    {
        return $this->state(fn (array $attributes) => [
            'notes' => fake()->sentence(),
        ]);
    }
}
