<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        $preliminaryOffer = fake()->randomFloat(2, 50, 500);
        $finalOffer = fake()->optional(0.7)->randomFloat(2, 50, $preliminaryOffer);

        return [
            'store_id' => Store::factory(),
            'customer_id' => Customer::factory(),
            'user_id' => User::factory(),
            // lead_number is auto-generated in model's booted() hook
            'status' => Lead::STATUS_PENDING_KIT_REQUEST,
            'type' => Lead::TYPE_MAIL_IN,
            'preliminary_offer' => $preliminaryOffer,
            'final_offer' => $finalOffer,
            'estimated_value' => fake()->randomFloat(2, $preliminaryOffer, $preliminaryOffer * 1.5),
            'payment_method' => null,
            'bin_location' => fake()->optional()->bothify('BIN-##??'),
            'customer_notes' => fake()->optional()->sentence(),
            'internal_notes' => null,
            'offer_given_at' => null,
            'offer_accepted_at' => null,
            'payment_processed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Lead::STATUS_PENDING_KIT_REQUEST,
        ]);
    }

    public function kitRequestConfirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Lead::STATUS_KIT_REQUEST_CONFIRMED,
        ]);
    }

    public function itemsReceived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Lead::STATUS_ITEMS_RECEIVED,
            'items_received_at' => now(),
        ]);
    }

    public function itemsReviewed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Lead::STATUS_ITEMS_REVIEWED,
            'items_received_at' => now()->subHours(2),
            'items_reviewed_at' => now(),
        ]);
    }

    public function offerGiven(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Lead::STATUS_OFFER_GIVEN,
            'final_offer' => $attributes['preliminary_offer'] ?? fake()->randomFloat(2, 50, 500),
            'offer_given_at' => now(),
        ]);
    }

    public function offerAccepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Lead::STATUS_OFFER_ACCEPTED,
            'final_offer' => $attributes['preliminary_offer'] ?? fake()->randomFloat(2, 50, 500),
            'offer_given_at' => now()->subHours(2),
            'offer_accepted_at' => now(),
        ]);
    }

    public function customerDeclined(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Lead::STATUS_CUSTOMER_DECLINED_OFFER,
            'offer_given_at' => now()->subHours(2),
            'internal_notes' => 'Customer declined the offer.',
        ]);
    }

    public function paymentProcessed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Lead::STATUS_PAYMENT_PROCESSED,
            'final_offer' => $attributes['preliminary_offer'] ?? fake()->randomFloat(2, 50, 500),
            'payment_method' => fake()->randomElement([
                Lead::PAYMENT_CASH,
                Lead::PAYMENT_CHECK,
                Lead::PAYMENT_ACH,
            ]),
            'offer_given_at' => now()->subDays(1),
            'offer_accepted_at' => now()->subHours(2),
            'payment_processed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Lead::STATUS_CANCELLED,
        ]);
    }

    public function mailIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Lead::TYPE_MAIL_IN,
        ]);
    }

    public function inStore(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Lead::TYPE_IN_STORE,
        ]);
    }

    public function withPaymentMethod(string $method): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => $method,
        ]);
    }

    public function withWarehouse(Warehouse $warehouse): static
    {
        return $this->state(fn (array $attributes) => [
            'warehouse_id' => $warehouse->id,
        ]);
    }
}
