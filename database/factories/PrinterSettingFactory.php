<?php

namespace Database\Factories;

use App\Models\PrinterSetting;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PrinterSetting>
 */
class PrinterSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->words(2, true).' Printer',
            'printer_type' => PrinterSetting::TYPE_ZEBRA,
            'top_offset' => 30,
            'left_offset' => 0,
            'right_offset' => 0,
            'text_size' => 20,
            'barcode_height' => 50,
            'line_height' => 25,
            'label_width' => 406,
            'label_height' => 203,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function godex(): static
    {
        return $this->state(fn (array $attributes) => [
            'printer_type' => PrinterSetting::TYPE_GODEX,
        ]);
    }
}
