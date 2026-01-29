<?php

namespace Database\Factories;

use App\Models\ChatSession;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatSession>
 */
class ChatSessionFactory extends Factory
{
    protected $model = ChatSession::class;

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
            'title' => fake()->sentence(4),
            'last_message_at' => now(),
        ];
    }

    /**
     * Indicate that the session has no title.
     */
    public function untitled(): static
    {
        return $this->state(fn () => [
            'title' => null,
        ]);
    }
}
