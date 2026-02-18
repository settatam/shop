<?php

use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\SalesChannel;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates platform listings for all existing products on all active channels.
     * The product is the template; each listing has its own price, title, description per channel.
     */
    public function up(): void
    {
        // Get all active sales channels grouped by store
        $channels = SalesChannel::where('is_active', true)->get()->groupBy('store_id');

        foreach ($channels as $storeId => $storeChannels) {
            // Get all products for this store
            $products = Product::where('store_id', $storeId)->get();

            foreach ($products as $product) {
                // Get default variant for pricing
                $defaultVariant = $product->variants()->first();
                $defaultPrice = $defaultVariant?->price ?? 0;
                $defaultQuantity = $defaultVariant?->quantity ?? 0;

                foreach ($storeChannels as $channel) {
                    // Skip if listing already exists
                    $exists = PlatformListing::where('product_id', $product->id)
                        ->where('sales_channel_id', $channel->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    PlatformListing::create([
                        'sales_channel_id' => $channel->id,
                        'store_marketplace_id' => $channel->store_marketplace_id,
                        'product_id' => $product->id,
                        'status' => 'active',
                        'platform_price' => $defaultPrice,
                        'platform_quantity' => $defaultQuantity,
                        'platform_data' => [
                            'title' => $product->title,
                            'description' => $product->description,
                        ],
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't delete listings as they may have been modified
    }
};
