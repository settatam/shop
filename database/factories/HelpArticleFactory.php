<?php

namespace Database\Factories;

use App\Models\HelpArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HelpArticle>
 */
class HelpArticleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => fake()->randomElement(HelpArticle::CATEGORIES),
            'title' => fake()->sentence(6),
            'content' => '<p>'.implode('</p><p>', fake()->paragraphs(3)).'</p>',
            'excerpt' => fake()->sentence(12),
            'sort_order' => fake()->numberBetween(0, 10),
            'is_published' => true,
        ];
    }

    /**
     * Mark the article as unpublished.
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => false,
        ]);
    }
}
