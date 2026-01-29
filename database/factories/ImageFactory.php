<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Image>
 */
class ImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->uuid().'.jpg';

        return [
            'store_id' => Store::factory(),
            'imageable_type' => Product::class,
            'imageable_id' => Product::factory(),
            'path' => 'uploads/'.$filename,
            'url' => 'https://example.com/cdn/'.$filename,
            'thumbnail_url' => 'https://example.com/cdn/thumbnails/'.$filename,
            'alt_text' => fake()->words(3, true),
            'disk' => 'do_spaces',
            'size' => fake()->numberBetween(10000, 5000000),
            'mime_type' => 'image/jpeg',
            'width' => fake()->numberBetween(800, 2000),
            'height' => fake()->numberBetween(600, 1500),
            'sort_order' => 0,
            'is_primary' => false,
        ];
    }

    /**
     * Indicate that the image is primary.
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }

    /**
     * Set the imageable model.
     */
    public function for(mixed $imageable): static
    {
        return $this->state(fn (array $attributes) => [
            'imageable_type' => get_class($imageable),
            'imageable_id' => $imageable->id,
            'store_id' => $imageable->store_id ?? $attributes['store_id'],
        ]);
    }
}
