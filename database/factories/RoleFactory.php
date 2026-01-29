<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Role>
 */
class RoleFactory extends Factory
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
            'name' => fake()->jobTitle(),
            'slug' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'permissions' => ['products.view', 'orders.view'],
            'is_default' => false,
            'is_system' => false,
        ];
    }

    /**
     * Create an owner role.
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Owner',
            'slug' => 'owner',
            'description' => 'Full access to all store features',
            'permissions' => ['*'],
            'is_system' => true,
        ]);
    }

    /**
     * Create an admin role.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Admin',
            'slug' => 'admin',
            'description' => 'Administrative access',
            'permissions' => Activity::getRolePresets()['admin']['permissions'],
        ]);
    }

    /**
     * Create a manager role.
     */
    public function manager(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Manager',
            'slug' => 'manager',
            'description' => 'Management access',
            'permissions' => Activity::getRolePresets()['manager']['permissions'],
        ]);
    }

    /**
     * Create a staff role.
     */
    public function staff(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Staff',
            'slug' => 'staff',
            'description' => 'Basic staff access',
            'permissions' => Activity::getRolePresets()['staff']['permissions'],
            'is_default' => true,
        ]);
    }

    /**
     * Create a viewer role.
     */
    public function viewer(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Viewer',
            'slug' => 'viewer',
            'description' => 'Read-only access',
            'permissions' => Activity::getRolePresets()['viewer']['permissions'],
        ]);
    }

    /**
     * Mark as default role.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * Mark as system role.
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => true,
        ]);
    }

    /**
     * Set specific permissions.
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn (array $attributes) => [
            'permissions' => $permissions,
        ]);
    }
}
