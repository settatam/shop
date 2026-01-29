<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerDocument>
 */
class CustomerDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => fake()->randomElement([
                CustomerDocument::TYPE_ID_FRONT,
                CustomerDocument::TYPE_ID_BACK,
                CustomerDocument::TYPE_OTHER,
            ]),
            'path' => 'customers/'.fake()->uuid().'/documents/'.fake()->uuid().'.jpg',
            'original_filename' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(100000, 5000000),
            'notes' => fake()->optional()->sentence(),
            'uploaded_by' => User::factory(),
        ];
    }

    public function idFront(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CustomerDocument::TYPE_ID_FRONT,
        ]);
    }

    public function idBack(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CustomerDocument::TYPE_ID_BACK,
        ]);
    }

    public function other(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CustomerDocument::TYPE_OTHER,
        ]);
    }

    public function pdf(): static
    {
        return $this->state(fn (array $attributes) => [
            'path' => 'customers/'.fake()->uuid().'/documents/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
        ]);
    }
}
