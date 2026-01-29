<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionOffer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TransactionOffer>
 */
class TransactionOfferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'user_id' => User::factory(),
            'amount' => $this->faker->randomFloat(2, 50, 5000),
            'status' => TransactionOffer::STATUS_PENDING,
            'admin_notes' => $this->faker->optional()->sentence(),
        ];
    }

    /**
     * Mark offer as pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionOffer::STATUS_PENDING,
        ]);
    }

    /**
     * Mark offer as accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionOffer::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }

    /**
     * Mark offer as declined.
     */
    public function declined(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionOffer::STATUS_DECLINED,
            'customer_response' => $this->faker->sentence(),
            'responded_at' => now(),
        ]);
    }

    /**
     * Mark offer as superseded.
     */
    public function superseded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionOffer::STATUS_SUPERSEDED,
        ]);
    }
}
