<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\VoiceMemory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VoiceMemory>
 */
class VoiceMemoryFactory extends Factory
{
    protected $model = VoiceMemory::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'memory_type' => fake()->randomElement(['fact', 'preference', 'commitment', 'context']),
            'category' => fake()->randomElement(['pricing', 'customers', 'operations', 'inventory']),
            'content' => fake()->sentence(),
            'confidence' => fake()->randomFloat(2, 0.5, 1.0),
            'source' => 'voice_conversation',
            'source_id' => null,
            'expires_at' => null,
            'is_active' => true,
        ];
    }

    public function fact(): static
    {
        return $this->state(fn () => [
            'memory_type' => 'fact',
        ]);
    }

    public function preference(): static
    {
        return $this->state(fn () => [
            'memory_type' => 'preference',
        ]);
    }

    public function commitment(): static
    {
        return $this->state(fn () => [
            'memory_type' => 'commitment',
        ]);
    }

    public function context(): static
    {
        return $this->state(fn () => [
            'memory_type' => 'context',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function highConfidence(): static
    {
        return $this->state(fn () => [
            'confidence' => fake()->randomFloat(2, 0.9, 1.0),
        ]);
    }

    public function lowConfidence(): static
    {
        return $this->state(fn () => [
            'confidence' => fake()->randomFloat(2, 0.3, 0.5),
        ]);
    }
}
