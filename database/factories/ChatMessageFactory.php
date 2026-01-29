<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_session_id' => ChatSession::factory(),
            'role' => fake()->randomElement(['user', 'assistant']),
            'content' => fake()->paragraph(),
            'tokens_used' => fake()->numberBetween(50, 500),
        ];
    }

    /**
     * Indicate this is a user message.
     */
    public function fromUser(): static
    {
        return $this->state(fn () => [
            'role' => 'user',
            'tokens_used' => 0,
        ]);
    }

    /**
     * Indicate this is an assistant message.
     */
    public function fromAssistant(): static
    {
        return $this->state(fn () => [
            'role' => 'assistant',
        ]);
    }

    /**
     * Include tool calls in the message.
     */
    public function withToolCalls(array $toolCalls = []): static
    {
        return $this->state(fn () => [
            'role' => 'assistant',
            'tool_calls' => $toolCalls ?: [
                ['name' => 'get_sales_summary', 'input' => ['period' => 'today']],
            ],
        ]);
    }
}
