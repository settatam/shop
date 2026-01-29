<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StoreUser>
 */
class StoreUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'store_id' => Store::factory(),
            'role_id' => Role::factory(),
            'is_owner' => false,
            'status' => 'active',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }

    /**
     * Set as store owner.
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_owner' => true,
        ]);
    }

    /**
     * Set as pending invite.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'invite sent',
        ]);
    }

    /**
     * Set as active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Use a specific role.
     */
    public function withRole(Role $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => $role->id,
        ]);
    }

    /**
     * Set the default warehouse.
     */
    public function withDefaultWarehouse(Warehouse $warehouse): static
    {
        return $this->state(fn (array $attributes) => [
            'default_warehouse_id' => $warehouse->id,
        ]);
    }
}
