<?php

namespace Database\Factories;

use App\Models\PaymentTerminal;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentTerminal>
 */
class PaymentTerminalFactory extends Factory
{
    protected $model = PaymentTerminal::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->randomElement(['Front Counter', 'Back Office', 'Mobile Terminal', 'Checkout 1']),
            'gateway' => PaymentTerminal::GATEWAY_SQUARE,
            'device_id' => 'dev_'.fake()->uuid(),
            'device_code' => null,
            'location_id' => 'loc_'.fake()->uuid(),
            'status' => PaymentTerminal::STATUS_ACTIVE,
            'settings' => [],
            'capabilities' => ['card', 'contactless', 'chip'],
            'last_seen_at' => now(),
            'paired_at' => now()->subDays(30),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentTerminal::STATUS_PENDING,
            'paired_at' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentTerminal::STATUS_ACTIVE,
            'paired_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentTerminal::STATUS_INACTIVE,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentTerminal::STATUS_DISCONNECTED,
            'last_seen_at' => now()->subHours(2),
        ]);
    }

    public function square(): static
    {
        return $this->state(fn (array $attributes) => [
            'gateway' => PaymentTerminal::GATEWAY_SQUARE,
        ]);
    }

    public function dejavoo(): static
    {
        return $this->state(fn (array $attributes) => [
            'gateway' => PaymentTerminal::GATEWAY_DEJAVOO,
        ]);
    }

    public function forWarehouse(Warehouse $warehouse): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => $warehouse->store_id,
            'warehouse_id' => $warehouse->id,
        ]);
    }

    public function atLocation(Warehouse $warehouse): static
    {
        return $this->forWarehouse($warehouse);
    }
}
