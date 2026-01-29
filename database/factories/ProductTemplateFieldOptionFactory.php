<?php

namespace Database\Factories;

use App\Models\ProductTemplateField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductTemplateFieldOption>
 */
class ProductTemplateFieldOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = fake()->word();

        return [
            'product_template_field_id' => ProductTemplateField::factory()->select(),
            'label' => ucfirst($value),
            'value' => $value,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
