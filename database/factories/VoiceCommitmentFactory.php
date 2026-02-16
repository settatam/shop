<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\User;
use App\Models\VoiceCommitment;
use App\Models\VoiceSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VoiceCommitment>
 */
class VoiceCommitmentFactory extends Factory
{
    protected $model = VoiceCommitment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'user_id' => User::factory(),
            'voice_session_id' => null,
            'commitment_type' => fake()->randomElement(['follow_up', 'reminder', 'action', 'promise']),
            'description' => fake()->sentence(),
            'due_at' => fake()->optional()->dateTimeBetween('now', '+1 week'),
            'status' => 'pending',
            'related_entity_type' => null,
            'related_entity_id' => null,
            'completed_at' => null,
            'metadata' => null,
        ];
    }

    public function withSession(): static
    {
        return $this->state(fn () => [
            'voice_session_id' => VoiceSession::factory(),
        ]);
    }

    public function reminder(): static
    {
        return $this->state(fn () => [
            'commitment_type' => 'reminder',
        ]);
    }

    public function followUp(): static
    {
        return $this->state(fn () => [
            'commitment_type' => 'follow_up',
        ]);
    }

    public function action(): static
    {
        return $this->state(fn () => [
            'commitment_type' => 'action',
        ]);
    }

    public function promise(): static
    {
        return $this->state(fn () => [
            'commitment_type' => 'promise',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'completed_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'due_at' => now()->subDays(fake()->numberBetween(1, 7)),
        ]);
    }

    public function dueSoon(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'due_at' => now()->addHours(fake()->numberBetween(1, 48)),
        ]);
    }

    public function dueToday(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'due_at' => today()->setHour(fake()->numberBetween(9, 18)),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
        ]);
    }
}
