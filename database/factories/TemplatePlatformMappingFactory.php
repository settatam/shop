<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\ProductTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TemplatePlatformMapping>
 */
class TemplatePlatformMappingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_template_id' => ProductTemplate::factory(),
            'platform' => fake()->randomElement(Platform::cases()),
            'field_mappings' => [
                'title' => 'title',
                'description' => 'description',
                'brand' => 'brand',
            ],
            'default_values' => [],
            'metafield_mappings' => [],
            'is_ai_generated' => fake()->boolean(30),
        ];
    }

    /**
     * Create mapping for a specific platform.
     */
    public function forPlatform(Platform $platform): static
    {
        return $this->state(fn () => ['platform' => $platform]);
    }

    /**
     * Mark as AI generated.
     */
    public function aiGenerated(): static
    {
        return $this->state(fn () => ['is_ai_generated' => true]);
    }

    /**
     * Create with metafield mappings.
     *
     * @param  array<string, array{namespace: string, key: string, enabled: bool}>  $metafieldMappings
     */
    public function withMetafieldMappings(array $metafieldMappings): static
    {
        return $this->state(fn () => ['metafield_mappings' => $metafieldMappings]);
    }

    /**
     * Create for Shopify platform with metafields.
     */
    public function forShopify(): static
    {
        return $this->state(fn () => [
            'platform' => Platform::Shopify,
            'metafield_mappings' => [
                'material' => ['namespace' => 'custom', 'key' => 'material', 'enabled' => true],
                'weight' => ['namespace' => 'custom', 'key' => 'product_weight', 'enabled' => true],
            ],
        ]);
    }
}
