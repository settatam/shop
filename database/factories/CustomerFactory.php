<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'zip' => fake()->postcode(),
            'accepts_marketing' => fake()->boolean(70),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withMarketing(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepts_marketing' => true,
        ]);
    }

    public function withIdNumber(): static
    {
        return $this->state(fn (array $attributes) => [
            'id_number' => fake()->regexify('[A-Z][0-9]{7}'),
            'id_issuing_state' => fake()->stateAbbr(),
            'id_expiration_date' => fake()->dateTimeBetween('+1 year', '+5 years'),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years'),
        ]);
    }

    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_name' => null,
            'last_name' => null,
            'email' => fake()->unique()->safeEmail(),
        ]);
    }
}
