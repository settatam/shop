<?php

namespace Database\Factories;

use App\Models\StoreMarketplace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShopifyMetafieldDefinition>
 */
class ShopifyMetafieldDefinitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->slug(2, false);

        return [
            'store_marketplace_id' => StoreMarketplace::factory()->shopify(),
            'key' => $key,
            'namespace' => 'custom',
            'name' => ucfirst(str_replace('-', ' ', $key)),
            'type' => fake()->randomElement([
                'single_line_text_field',
                'number_integer',
                'number_decimal',
                'boolean',
                'json',
            ]),
            'description' => fake()->optional()->sentence(),
            'shopify_gid' => 'gid://shopify/MetafieldDefinition/'.fake()->unique()->randomNumber(9),
        ];
    }
}
