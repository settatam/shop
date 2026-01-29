<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get or create a store
        $store = Store::first();

        if (! $store) {
            $this->command->warn('No store found. Please create a store first.');

            return;
        }

        // Create categories
        $categories = [
            'Electronics' => ['Smartphones', 'Laptops', 'Accessories'],
            'Clothing' => ['Men', 'Women', 'Kids'],
            'Home & Garden' => ['Furniture', 'Decor', 'Kitchen'],
        ];

        $categoryModels = [];
        foreach ($categories as $parentName => $children) {
            $parent = Category::firstOrCreate(
                ['name' => $parentName, 'store_id' => $store->id],
                [
                    'slug' => \Illuminate\Support\Str::slug($parentName),
                    'description' => "All {$parentName} products",
                    'level' => 0,
                ]
            );
            $categoryModels[] = $parent;

            foreach ($children as $childName) {
                $child = Category::firstOrCreate(
                    ['name' => $childName, 'store_id' => $store->id, 'parent_id' => $parent->id],
                    [
                        'slug' => \Illuminate\Support\Str::slug($childName),
                        'description' => "{$childName} products",
                        'level' => 1,
                    ]
                );
                $categoryModels[] = $child;
            }
        }

        // Create brands
        $brandNames = ['Apple', 'Samsung', 'Nike', 'Adidas', 'Sony', 'LG', 'Dell', 'HP', 'Ikea', 'West Elm'];
        $brands = [];
        foreach ($brandNames as $brandName) {
            $brands[] = Brand::firstOrCreate(
                ['name' => $brandName, 'store_id' => $store->id],
                [
                    'slug' => \Illuminate\Support\Str::slug($brandName),
                    'description' => "{$brandName} products",
                ]
            );
        }

        // Sample products data
        $products = [
            [
                'title' => 'iPhone 15 Pro',
                'description' => 'The latest iPhone with A17 Pro chip, featuring a titanium design.',
                'category' => 'Smartphones',
                'brand' => 'Apple',
                'variants' => [
                    ['title' => '128GB - Natural', 'sku' => 'IPH15P-128-NAT', 'price' => 999, 'cost' => 750, 'quantity' => 25],
                    ['title' => '256GB - Natural', 'sku' => 'IPH15P-256-NAT', 'price' => 1099, 'cost' => 850, 'quantity' => 20],
                    ['title' => '512GB - Natural', 'sku' => 'IPH15P-512-NAT', 'price' => 1299, 'cost' => 950, 'quantity' => 15],
                ],
            ],
            [
                'title' => 'Samsung Galaxy S24 Ultra',
                'description' => 'Premium Android smartphone with S Pen and AI features.',
                'category' => 'Smartphones',
                'brand' => 'Samsung',
                'variants' => [
                    ['title' => '256GB - Black', 'sku' => 'SGS24U-256-BLK', 'price' => 1199, 'cost' => 900, 'quantity' => 30],
                    ['title' => '512GB - Black', 'sku' => 'SGS24U-512-BLK', 'price' => 1399, 'cost' => 1050, 'quantity' => 20],
                ],
            ],
            [
                'title' => 'MacBook Pro 14"',
                'description' => 'Professional laptop with M3 Pro chip and stunning display.',
                'category' => 'Laptops',
                'brand' => 'Apple',
                'variants' => [
                    ['title' => 'M3 Pro 18GB/512GB', 'sku' => 'MBP14-M3P-18-512', 'price' => 1999, 'cost' => 1500, 'quantity' => 10],
                    ['title' => 'M3 Max 36GB/1TB', 'sku' => 'MBP14-M3M-36-1T', 'price' => 3499, 'cost' => 2600, 'quantity' => 5],
                ],
            ],
            [
                'title' => 'Dell XPS 15',
                'description' => 'Ultra-thin laptop with InfinityEdge display.',
                'category' => 'Laptops',
                'brand' => 'Dell',
                'variants' => [
                    ['title' => 'i7/16GB/512GB', 'sku' => 'DXPS15-I7-16-512', 'price' => 1499, 'cost' => 1100, 'quantity' => 15],
                    ['title' => 'i9/32GB/1TB', 'sku' => 'DXPS15-I9-32-1T', 'price' => 2199, 'cost' => 1650, 'quantity' => 8],
                ],
            ],
            [
                'title' => 'Nike Air Max 270',
                'description' => 'Classic sneakers with Air Max cushioning.',
                'category' => 'Men',
                'brand' => 'Nike',
                'variants' => [
                    ['title' => 'Size 9 - Black/White', 'sku' => 'NAM270-9-BW', 'price' => 150, 'cost' => 75, 'quantity' => 50],
                    ['title' => 'Size 10 - Black/White', 'sku' => 'NAM270-10-BW', 'price' => 150, 'cost' => 75, 'quantity' => 45],
                    ['title' => 'Size 11 - Black/White', 'sku' => 'NAM270-11-BW', 'price' => 150, 'cost' => 75, 'quantity' => 40],
                ],
            ],
            [
                'title' => 'Adidas Ultraboost 22',
                'description' => 'Premium running shoes with Boost technology.',
                'category' => 'Men',
                'brand' => 'Adidas',
                'variants' => [
                    ['title' => 'Size 9 - Core Black', 'sku' => 'AUB22-9-CB', 'price' => 190, 'cost' => 95, 'quantity' => 35],
                    ['title' => 'Size 10 - Core Black', 'sku' => 'AUB22-10-CB', 'price' => 190, 'cost' => 95, 'quantity' => 40],
                    ['title' => 'Size 11 - Core Black', 'sku' => 'AUB22-11-CB', 'price' => 190, 'cost' => 95, 'quantity' => 30],
                ],
            ],
            [
                'title' => 'Sony WH-1000XM5',
                'description' => 'Industry-leading noise cancelling headphones.',
                'category' => 'Accessories',
                'brand' => 'Sony',
                'variants' => [
                    ['title' => 'Black', 'sku' => 'SWXM5-BLK', 'price' => 399, 'cost' => 250, 'quantity' => 60],
                    ['title' => 'Silver', 'sku' => 'SWXM5-SLV', 'price' => 399, 'cost' => 250, 'quantity' => 45],
                ],
            ],
            [
                'title' => 'IKEA MALM Bed Frame',
                'description' => 'Clean design bed frame with 4 storage boxes.',
                'category' => 'Furniture',
                'brand' => 'Ikea',
                'variants' => [
                    ['title' => 'Queen - White', 'sku' => 'MALM-Q-WHT', 'price' => 449, 'cost' => 200, 'quantity' => 20],
                    ['title' => 'Queen - Black', 'sku' => 'MALM-Q-BLK', 'price' => 449, 'cost' => 200, 'quantity' => 15],
                    ['title' => 'King - White', 'sku' => 'MALM-K-WHT', 'price' => 549, 'cost' => 250, 'quantity' => 12],
                ],
            ],
            [
                'title' => 'LG 55" OLED TV',
                'description' => 'Stunning 4K OLED display with perfect blacks.',
                'category' => 'Electronics',
                'brand' => 'LG',
                'variants' => [
                    ['title' => 'C3 Series', 'sku' => 'LG55-C3', 'price' => 1499, 'cost' => 1000, 'quantity' => 8],
                    ['title' => 'G3 Series', 'sku' => 'LG55-G3', 'price' => 1999, 'cost' => 1350, 'quantity' => 5],
                ],
            ],
            [
                'title' => 'HP Spectre x360',
                'description' => '2-in-1 convertible laptop with OLED display.',
                'category' => 'Laptops',
                'brand' => 'HP',
                'variants' => [
                    ['title' => 'i7/16GB/512GB', 'sku' => 'HPSX360-I7-16-512', 'price' => 1399, 'cost' => 1000, 'quantity' => 12],
                ],
            ],
        ];

        // Create products
        foreach ($products as $productData) {
            $category = Category::where('name', $productData['category'])->where('store_id', $store->id)->first();
            $brand = Brand::where('name', $productData['brand'])->where('store_id', $store->id)->first();
            $handle = \Illuminate\Support\Str::slug($productData['title']);

            // Check if product already exists
            $existingProduct = Product::where('store_id', $store->id)->where('handle', $handle)->first();
            if ($existingProduct) {
                continue;
            }

            $product = Product::create([
                'store_id' => $store->id,
                'title' => $productData['title'],
                'description' => $productData['description'],
                'handle' => $handle,
                'category_id' => $category?->id,
                'brand_id' => $brand?->id,
                'is_published' => true,
                'is_draft' => false,
                'has_variants' => count($productData['variants']) > 1,
                'track_quantity' => true,
                'sell_out_of_stock' => false,
            ]);

            // Create variants
            foreach ($productData['variants'] as $variantData) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => $variantData['sku'],
                    'price' => $variantData['price'],
                    'cost' => $variantData['cost'],
                    'quantity' => $variantData['quantity'],
                    'option1_name' => 'Variant',
                    'option1_value' => $variantData['title'],
                    'is_active' => true,
                    'status' => 'active',
                ]);
            }
        }

        // Create additional random products
        Product::factory()
            ->count(20)
            ->state(['store_id' => $store->id])
            ->sequence(fn ($sequence) => [
                'category_id' => $categoryModels[array_rand($categoryModels)]->id ?? null,
                'brand_id' => $brands[array_rand($brands)]->id ?? null,
                'is_published' => fake()->boolean(80),
                'is_draft' => fake()->boolean(20),
            ])
            ->create()
            ->each(function ($product) {
                // Add 1-3 variants per product
                $variantCount = fake()->numberBetween(1, 3);
                ProductVariant::factory()
                    ->count($variantCount)
                    ->state(['product_id' => $product->id])
                    ->create();

                $product->update(['has_variants' => $variantCount > 1]);
            });

        $this->command->info('Products seeded successfully!');
        $this->command->info('Created '.Product::where('store_id', $store->id)->count().' products.');
    }
}
