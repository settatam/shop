<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Address>
 */
class AddressFactory extends Factory
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
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company' => fake()->optional(0.3)->company(),
            'address' => fake()->streetAddress(),
            'address2' => fake()->optional(0.2)->secondaryAddress(),
            'city' => fake()->city(),
            'state_id' => fake()->numberBetween(1, 50),
            'country_id' => 1, // US
            'zip' => fake()->postcode(),
            'phone' => fake()->phoneNumber(),
            'is_default' => false,
            'is_shipping' => true,
            'is_billing' => false,
            'is_verified' => false,
            'type' => Address::TYPE_HOME,
        ];
    }

    /**
     * Indicate that the address is the default.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * Indicate that the address is for shipping.
     */
    public function shipping(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_shipping' => true,
            'type' => Address::TYPE_SHIPPING,
        ]);
    }

    /**
     * Indicate that the address is for billing.
     */
    public function billing(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_billing' => true,
            'type' => Address::TYPE_BILLING,
        ]);
    }

    /**
     * Indicate that the address is verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
        ]);
    }

    /**
     * Associate the address with a customer.
     */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes) => [
            'addressable_type' => Customer::class,
            'addressable_id' => $customer->id,
            'store_id' => $customer->store_id,
        ]);
    }

    /**
     * Associate the address with a store.
     */
    public function forStore(Store $store): static
    {
        return $this->state(fn (array $attributes) => [
            'addressable_type' => Store::class,
            'addressable_id' => $store->id,
            'store_id' => $store->id,
        ]);
    }
}
