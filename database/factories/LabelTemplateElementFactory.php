<?php

namespace Database\Factories;

use App\Models\LabelTemplate;
use App\Models\LabelTemplateElement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LabelTemplateElement>
 */
class LabelTemplateElementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'label_template_id' => LabelTemplate::factory(),
            'element_type' => LabelTemplateElement::TYPE_TEXT_FIELD,
            'x' => fake()->numberBetween(0, 200),
            'y' => fake()->numberBetween(0, 100),
            'width' => 150,
            'height' => 25,
            'content' => 'variant.sku',
            'styles' => ['fontSize' => 20, 'alignment' => 'left'],
            'sort_order' => 0,
        ];
    }

    public function barcode(): static
    {
        return $this->state(fn (array $attributes) => [
            'element_type' => LabelTemplateElement::TYPE_BARCODE,
            'width' => 200,
            'height' => 60,
            'content' => 'variant.barcode',
            'styles' => ['barcodeHeight' => 50, 'showText' => true],
        ]);
    }

    public function staticText(string $text = 'Sample'): static
    {
        return $this->state(fn (array $attributes) => [
            'element_type' => LabelTemplateElement::TYPE_STATIC_TEXT,
            'content' => $text,
        ]);
    }

    public function line(): static
    {
        return $this->state(fn (array $attributes) => [
            'element_type' => LabelTemplateElement::TYPE_LINE,
            'height' => 2,
            'content' => null,
            'styles' => ['thickness' => 2],
        ]);
    }
}
