<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VoiceSession>
 */
class VoiceSessionFactory extends Factory
{
    protected $model = VoiceSession::class;

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
            'gateway_session_id' => Str::uuid()->toString(),
            'status' => 'active',
            'started_at' => now(),
            'ended_at' => null,
            'total_duration_seconds' => 0,
            'metadata' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'started_at' => now(),
            'ended_at' => null,
        ]);
    }

    public function ended(): static
    {
        $duration = fake()->numberBetween(30, 600);

        return $this->state(fn () => [
            'status' => 'ended',
            'started_at' => now()->subSeconds($duration),
            'ended_at' => now(),
            'total_duration_seconds' => $duration,
        ]);
    }

    public function withError(): static
    {
        return $this->state(fn () => [
            'status' => 'error',
            'ended_at' => now(),
            'metadata' => ['error' => fake()->sentence()],
        ]);
    }
}
