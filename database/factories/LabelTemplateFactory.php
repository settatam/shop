<?php

namespace Database\Factories;

use App\Models\LabelTemplate;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LabelTemplate>
 */
class LabelTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->words(2, true).' Label',
            'type' => LabelTemplate::TYPE_PRODUCT,
            'canvas_width' => 406,
            'canvas_height' => 203,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function transaction(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LabelTemplate::TYPE_TRANSACTION,
        ]);
    }
}
