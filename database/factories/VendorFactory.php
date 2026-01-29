<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->company(),
            'code' => fake()->unique()->bothify('VND-####'),
            'company_name' => fake()->optional()->company(),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'website' => fake()->optional()->url(),
            'address_line1' => fake()->streetAddress(),
            'address_line2' => fake()->optional()->secondaryAddress(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'postal_code' => fake()->postcode(),
            'country' => fake()->country(),
            'tax_id' => fake()->optional()->ein(),
            'payment_terms' => fake()->randomElement(Vendor::PAYMENT_TERMS),
            'lead_time_days' => fake()->numberBetween(1, 30),
            'currency_code' => 'USD',
            'contact_name' => fake()->name(),
            'contact_email' => fake()->safeEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'is_active' => true,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function withNoAddress(): static
    {
        return $this->state(fn () => [
            'address_line1' => null,
            'address_line2' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'country' => null,
        ]);
    }

    public function prepaid(): static
    {
        return $this->state(fn () => [
            'payment_terms' => Vendor::PAYMENT_TERMS_PREPAID,
        ]);
    }

    public function net30(): static
    {
        return $this->state(fn () => [
            'payment_terms' => Vendor::PAYMENT_TERMS_NET_30,
        ]);
    }
}
