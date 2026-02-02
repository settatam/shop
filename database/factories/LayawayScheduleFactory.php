<?php

namespace Database\Factories;

use App\Models\Layaway;
use App\Models\LayawaySchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LayawaySchedule>
 */
class LayawayScheduleFactory extends Factory
{
    protected $model = LayawaySchedule::class;

    public function definition(): array
    {
        return [
            'layaway_id' => Layaway::factory(),
            'installment_number' => fake()->numberBetween(1, 4),
            'due_date' => now()->addDays(fake()->numberBetween(7, 90)),
            'amount_due' => fake()->randomFloat(2, 50, 500),
            'amount_paid' => 0,
            'status' => LayawaySchedule::STATUS_PENDING,
            'paid_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LayawaySchedule::STATUS_PENDING,
            'paid_at' => null,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LayawaySchedule::STATUS_PAID,
            'amount_paid' => $attributes['amount_due'],
            'paid_at' => now(),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LayawaySchedule::STATUS_OVERDUE,
            'due_date' => now()->subDays(7),
        ]);
    }

    public function partiallyPaid(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_paid' => $amount,
        ]);
    }

    public function dueSoon(int $days = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => now()->addDays($days),
            'status' => LayawaySchedule::STATUS_PENDING,
        ]);
    }

    public function withInstallmentNumber(int $number): static
    {
        return $this->state(fn (array $attributes) => [
            'installment_number' => $number,
        ]);
    }

    public function withAmount(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_due' => $amount,
        ]);
    }

    public function withDueDate(\DateTimeInterface $date): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => $date,
        ]);
    }
}
