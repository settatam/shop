<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Certification>
 */
class CertificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lab' => fake()->randomElement(['GIA', 'AGS', 'IGI']),
            'certificate_number' => fake()->unique()->numerify('##########'),
            'issue_date' => fake()->date(),
            'shape' => fake()->randomElement(['Round Brilliant', 'Princess', 'Cushion', 'Oval', 'Emerald']),
            'carat_weight' => fake()->randomFloat(2, 0.5, 5.0),
            'color_grade' => fake()->randomElement(['D', 'E', 'F', 'G', 'H', 'I', 'J']),
            'clarity_grade' => fake()->randomElement(['FL', 'IF', 'VVS1', 'VVS2', 'VS1', 'VS2', 'SI1', 'SI2']),
            'cut_grade' => fake()->randomElement(['Excellent', 'Very Good', 'Good', 'Fair']),
            'polish' => fake()->randomElement(['Excellent', 'Very Good', 'Good']),
            'symmetry' => fake()->randomElement(['Excellent', 'Very Good', 'Good']),
            'fluorescence' => fake()->randomElement(['None', 'Faint', 'Medium', 'Strong']),
        ];
    }

    /**
     * Indicate this is a GIA certificate.
     */
    public function gia(): static
    {
        return $this->state(fn (array $attributes) => [
            'lab' => 'GIA',
        ]);
    }
}
