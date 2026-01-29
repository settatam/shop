<?php

namespace Database\Factories;

use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductTemplateField>
 */
class ProductTemplateFieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->words(2, true);

        return [
            'product_template_id' => ProductTemplate::factory(),
            'name' => Str::slug($label, '_'),
            'label' => ucfirst($label),
            'type' => fake()->randomElement(ProductTemplateField::TYPES),
            'placeholder' => fake()->optional()->sentence(3),
            'help_text' => fake()->optional()->sentence(),
            'default_value' => null,
            'is_required' => fake()->boolean(30),
            'is_searchable' => fake()->boolean(20),
            'is_filterable' => fake()->boolean(20),
            'show_in_listing' => fake()->boolean(30),
            'validation_rules' => null,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * Create a text field.
     */
    public function text(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductTemplateField::TYPE_TEXT,
        ]);
    }

    /**
     * Create a select field.
     */
    public function select(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductTemplateField::TYPE_SELECT,
        ]);
    }

    /**
     * Create a required field.
     */
    public function required(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => true,
        ]);
    }
}
