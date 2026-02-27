<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\StoreKnowledgeBaseEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StoreKnowledgeBaseEntry>
 */
class StoreKnowledgeBaseEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'type' => $this->faker->randomElement(StoreKnowledgeBaseEntry::VALID_TYPES),
            'title' => $this->faker->sentence(3),
            'content' => $this->faker->paragraph(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function returnPolicy(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StoreKnowledgeBaseEntry::TYPE_RETURN_POLICY,
            'title' => 'Return Policy',
            'content' => 'We accept returns within 30 days of purchase with original packaging.',
        ]);
    }

    public function shippingInfo(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StoreKnowledgeBaseEntry::TYPE_SHIPPING_INFO,
            'title' => 'Shipping Information',
            'content' => 'Free shipping on orders over $100. Standard shipping takes 3-5 business days.',
        ]);
    }

    public function careInstructions(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StoreKnowledgeBaseEntry::TYPE_CARE_INSTRUCTIONS,
            'title' => 'Jewelry Care',
            'content' => 'Clean your jewelry with a soft cloth. Avoid exposure to harsh chemicals.',
        ]);
    }

    public function faq(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StoreKnowledgeBaseEntry::TYPE_FAQ,
            'title' => $this->faker->sentence(4).'?',
            'content' => $this->faker->paragraph(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
