<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\StoreMarketplace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlatformOrder>
 */
class PlatformOrderFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 20, 500);
        $shipping = fake()->randomFloat(2, 0, 25);
        $tax = $subtotal * 0.08;
        $total = $subtotal + $shipping + $tax;

        return [
            'store_marketplace_id' => StoreMarketplace::factory(),
            'order_id' => null,
            'external_order_id' => fake()->uuid(),
            'external_order_number' => '#'.fake()->numerify('####'),
            'status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'payment_status' => 'paid',
            'total' => $total,
            'subtotal' => $subtotal,
            'shipping_cost' => $shipping,
            'tax' => $tax,
            'discount' => 0,
            'currency' => 'USD',
            'customer_data' => [
                'email' => fake()->email(),
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'phone' => fake()->phoneNumber(),
            ],
            'shipping_address' => [
                'address_line1' => fake()->streetAddress(),
                'city' => fake()->city(),
                'state' => fake()->stateAbbr(),
                'postal_code' => fake()->postcode(),
                'country' => 'US',
            ],
            'billing_address' => null,
            'line_items' => [
                [
                    'external_id' => fake()->uuid(),
                    'sku' => fake()->regexify('[A-Z]{3}-[0-9]{5}'),
                    'title' => fake()->words(3, true),
                    'quantity' => fake()->numberBetween(1, 3),
                    'price' => $subtotal,
                    'discount' => 0,
                    'tax' => 0,
                ],
            ],
            'platform_data' => [],
            'ordered_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'last_synced_at' => null,
        ];
    }

    public function imported(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => Order::factory(),
            'last_synced_at' => now(),
        ]);
    }

    public function fulfilled(): static
    {
        return $this->state(fn (array $attributes) => [
            'fulfillment_status' => 'fulfilled',
            'status' => 'completed',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
    }
}
