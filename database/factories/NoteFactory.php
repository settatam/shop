<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Note>
 */
class NoteFactory extends Factory
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
            'user_id' => User::factory(),
            'content' => fake()->paragraph(),
        ];
    }

    /**
     * Set a specific notable model.
     */
    public function forNotable($notable): static
    {
        return $this->state(fn (array $attributes) => [
            'notable_type' => get_class($notable),
            'notable_id' => $notable->id,
        ]);
    }
}
