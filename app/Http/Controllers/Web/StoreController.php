<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    /**
     * Switch to a different store.
     */
    public function switch(Request $request, Store $store): RedirectResponse
    {
        $user = $request->user();

        // Verify user has access to this store
        $hasAccess = StoreUser::where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->exists();

        if (! $hasAccess && $store->user_id !== $user->id) {
            return back()->with('error', 'You do not have access to that store.');
        }

        // Update user's current store
        $user->update(['current_store_id' => $store->id]);

        // Update session
        $request->session()->put('current_store_id', $store->id);

        return redirect()->route('dashboard')
            ->with('status', 'Switched to '.$store->name);
    }

    /**
     * Create a new store.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:2'],
            'industry' => ['nullable', 'string', 'max:50'],
            'create_sample_data' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        // Create the store
        $store = Store::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
            'account_email' => $user->email,
            'is_active' => true,
            'address' => $validated['address_line1'] ?? null,
            'address2' => $validated['address_line2'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'zip' => $validated['postal_code'] ?? null,
        ]);

        // Create default roles
        Role::createDefaultRoles($store->id);

        // Get the owner role
        $ownerRole = Role::where('store_id', $store->id)
            ->where('slug', Role::OWNER)
            ->first();

        // Create store user record for the owner
        StoreUser::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $ownerRole?->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => explode(' ', $user->name)[0] ?? $user->name,
            'last_name' => explode(' ', $user->name, 2)[1] ?? '',
            'email' => $user->email,
        ]);

        // Create default warehouse
        $warehouse = Warehouse::create([
            'store_id' => $store->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
            'accepts_transfers' => true,
            'fulfills_orders' => true,
            'priority' => 1,
            'address_line1' => $validated['address_line1'] ?? null,
            'address_line2' => $validated['address_line2'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'country' => $validated['country'] ?? null,
        ]);

        // Create categories based on industry
        $industry = $validated['industry'] ?? 'other';
        $categories = $this->createIndustryCategories($store->id, $industry);

        // Create sample products if requested
        if ($validated['create_sample_data'] ?? false) {
            $this->createSampleProducts($store->id, $warehouse->id, $categories, $industry);
        }

        // Switch to the new store
        $user->update(['current_store_id' => $store->id]);
        $request->session()->put('current_store_id', $store->id);

        return redirect()->route('dashboard')
            ->with('status', 'Store "'.$store->name.'" created successfully!');
    }

    /**
     * Create categories based on industry selection.
     *
     * @return array<int, Category>
     */
    protected function createIndustryCategories(int $storeId, string $industry): array
    {
        $industryCategories = [
            'jewelry' => ['Rings', 'Necklaces', 'Bracelets', 'Earrings', 'Watches'],
            'electronics' => ['Phones', 'Computers', 'Tablets', 'Accessories', 'Audio'],
            'clothing' => ["Men's Clothing", "Women's Clothing", "Kids' Clothing", 'Shoes', 'Accessories'],
            'home' => ['Furniture', 'Decor', 'Kitchen', 'Bedding', 'Garden'],
            'sports' => ['Fitness', 'Team Sports', 'Outdoor Recreation', 'Camping', 'Cycling'],
            'beauty' => ['Skincare', 'Makeup', 'Haircare', 'Fragrances', 'Tools'],
            'toys' => ['Action Figures', 'Board Games', 'Educational', 'Outdoor Toys', 'Puzzles'],
            'other' => ['General'],
        ];

        $categoryNames = $industryCategories[$industry] ?? $industryCategories['other'];
        $categories = [];

        foreach ($categoryNames as $index => $name) {
            $categories[] = Category::create([
                'store_id' => $storeId,
                'name' => $name,
                'slug' => Str::slug($name),
                'position' => $index,
                'is_active' => true,
            ]);
        }

        return $categories;
    }

    /**
     * Create sample products for the store.
     *
     * @param  array<int, Category>  $categories
     */
    protected function createSampleProducts(int $storeId, int $warehouseId, array $categories, string $industry): void
    {
        $sampleProducts = $this->getSampleProductsForIndustry($industry);

        foreach ($sampleProducts as $index => $productData) {
            $category = $categories[$index % count($categories)] ?? $categories[0] ?? null;

            $product = Product::create([
                'store_id' => $storeId,
                'category_id' => $category?->id,
                'title' => $productData['title'],
                'description' => $productData['description'],
                'handle' => Str::slug($productData['title']),
                'status' => 'draft',
                'is_published' => false,
                'track_quantity' => true,
            ]);

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => 'SAMPLE-'.strtoupper(Str::random(6)),
                'price' => $productData['price'],
                'cost' => $productData['cost'],
                'quantity' => $productData['quantity'],
                'is_active' => true,
            ]);

            // Create inventory record
            \App\Models\Inventory::create([
                'store_id' => $storeId,
                'product_variant_id' => $variant->id,
                'warehouse_id' => $warehouseId,
                'quantity' => $productData['quantity'],
                'unit_cost' => $productData['cost'],
            ]);
        }
    }

    /**
     * Get sample products for a specific industry.
     *
     * @return array<int, array{title: string, description: string, price: float, cost: float, quantity: int}>
     */
    protected function getSampleProductsForIndustry(string $industry): array
    {
        $samples = [
            'jewelry' => [
                [
                    'title' => 'Classic Gold Ring',
                    'description' => 'A beautiful classic gold ring, perfect for any occasion. Features a timeless design that never goes out of style.',
                    'price' => 299.99,
                    'cost' => 150.00,
                    'quantity' => 10,
                ],
                [
                    'title' => 'Silver Chain Necklace',
                    'description' => 'Elegant silver chain necklace with a delicate pendant. Perfect for everyday wear or special occasions.',
                    'price' => 149.99,
                    'cost' => 75.00,
                    'quantity' => 15,
                ],
            ],
            'electronics' => [
                [
                    'title' => 'Wireless Bluetooth Earbuds',
                    'description' => 'High-quality wireless earbuds with noise cancellation. Long battery life and comfortable fit.',
                    'price' => 79.99,
                    'cost' => 35.00,
                    'quantity' => 50,
                ],
                [
                    'title' => 'USB-C Charging Cable',
                    'description' => 'Durable braided USB-C cable, 6ft length. Fast charging compatible with all USB-C devices.',
                    'price' => 19.99,
                    'cost' => 5.00,
                    'quantity' => 100,
                ],
            ],
            'clothing' => [
                [
                    'title' => 'Classic Cotton T-Shirt',
                    'description' => '100% organic cotton t-shirt. Comfortable, breathable, and perfect for everyday wear.',
                    'price' => 29.99,
                    'cost' => 12.00,
                    'quantity' => 100,
                ],
                [
                    'title' => 'Denim Jeans',
                    'description' => 'Premium quality denim jeans with a modern fit. Durable and stylish for any casual occasion.',
                    'price' => 69.99,
                    'cost' => 28.00,
                    'quantity' => 50,
                ],
            ],
            'home' => [
                [
                    'title' => 'Decorative Throw Pillow',
                    'description' => 'Soft and stylish decorative pillow. Perfect accent piece for your living room or bedroom.',
                    'price' => 34.99,
                    'cost' => 15.00,
                    'quantity' => 30,
                ],
                [
                    'title' => 'Ceramic Plant Pot',
                    'description' => 'Beautiful ceramic pot for indoor plants. Modern design with drainage hole included.',
                    'price' => 24.99,
                    'cost' => 10.00,
                    'quantity' => 40,
                ],
            ],
            'sports' => [
                [
                    'title' => 'Yoga Mat',
                    'description' => 'Premium non-slip yoga mat. Extra thick for comfort and durability. Includes carrying strap.',
                    'price' => 39.99,
                    'cost' => 18.00,
                    'quantity' => 25,
                ],
                [
                    'title' => 'Resistance Bands Set',
                    'description' => 'Set of 5 resistance bands with varying resistance levels. Perfect for home workouts.',
                    'price' => 24.99,
                    'cost' => 8.00,
                    'quantity' => 60,
                ],
            ],
            'beauty' => [
                [
                    'title' => 'Moisturizing Face Cream',
                    'description' => 'Hydrating face cream with natural ingredients. Suitable for all skin types.',
                    'price' => 44.99,
                    'cost' => 18.00,
                    'quantity' => 40,
                ],
                [
                    'title' => 'Lip Gloss Set',
                    'description' => 'Set of 4 lip glosses in trending colors. Long-lasting and moisturizing formula.',
                    'price' => 29.99,
                    'cost' => 10.00,
                    'quantity' => 50,
                ],
            ],
            'toys' => [
                [
                    'title' => 'Building Blocks Set',
                    'description' => '100-piece colorful building blocks. Educational toy for kids ages 3 and up.',
                    'price' => 34.99,
                    'cost' => 14.00,
                    'quantity' => 30,
                ],
                [
                    'title' => 'Puzzle Game',
                    'description' => '500-piece jigsaw puzzle with beautiful landscape design. Great for family fun.',
                    'price' => 19.99,
                    'cost' => 7.00,
                    'quantity' => 45,
                ],
            ],
        ];

        return $samples[$industry] ?? [
            [
                'title' => 'Sample Product 1',
                'description' => 'This is a sample product to help you get started. Edit or delete it anytime.',
                'price' => 29.99,
                'cost' => 15.00,
                'quantity' => 10,
            ],
            [
                'title' => 'Sample Product 2',
                'description' => 'Another sample product. Use this as a template for your own products.',
                'price' => 49.99,
                'cost' => 25.00,
                'quantity' => 10,
            ],
        ];
    }
}
