<?php

namespace Database\Factories;

use App\Models\ShippingLabel;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShippingLabel>
 */
class ShippingLabelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'shippable_type' => Transaction::class,
            'shippable_id' => Transaction::factory(),
            'type' => $this->faker->randomElement([ShippingLabel::TYPE_OUTBOUND, ShippingLabel::TYPE_RETURN]),
            'carrier' => ShippingLabel::CARRIER_FEDEX,
            'tracking_number' => $this->faker->numerify('############'),
            'service_type' => 'FEDEX_GROUND',
            'label_format' => 'PDF',
            'status' => ShippingLabel::STATUS_CREATED,
            'sender_address' => [
                'name' => $this->faker->name(),
                'street' => $this->faker->streetAddress(),
                'city' => $this->faker->city(),
                'state' => $this->faker->stateAbbr(),
                'postal_code' => $this->faker->postcode(),
                'country' => 'US',
            ],
            'recipient_address' => [
                'name' => $this->faker->name(),
                'street' => $this->faker->streetAddress(),
                'city' => $this->faker->city(),
                'state' => $this->faker->stateAbbr(),
                'postal_code' => $this->faker->postcode(),
                'country' => 'US',
            ],
            'shipping_cost' => $this->faker->randomFloat(2, 5, 50),
        ];
    }

    /**
     * Mark label as outbound.
     */
    public function outbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ShippingLabel::TYPE_OUTBOUND,
        ]);
    }

    /**
     * Mark label as return.
     */
    public function return(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ShippingLabel::TYPE_RETURN,
        ]);
    }

    /**
     * Mark label as voided.
     */
    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShippingLabel::STATUS_VOIDED,
        ]);
    }

    /**
     * Mark label as delivered.
     */
    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShippingLabel::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }
}
