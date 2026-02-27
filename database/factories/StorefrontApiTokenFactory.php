<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\StorefrontApiToken;
use App\Models\StoreMarketplace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StorefrontApiToken>
 */
class StorefrontApiTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'store_marketplace_id' => StoreMarketplace::factory(),
            'token' => StorefrontApiToken::generateToken(),
            'name' => 'Default',
            'is_active' => true,
            'settings' => [
                'welcome_message' => "Hi! I'm your jewelry assistant.",
                'accent_color' => '#2563eb',
                'assistant_name' => 'Jewelry Assistant',
            ],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
