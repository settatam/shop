<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Copy ProductPlatformOverride data into matching PlatformListing rows
        if (DB::getSchemaBuilder()->hasTable('product_platform_overrides')) {
            DB::table('product_platform_overrides')
                ->orderBy('id')
                ->chunkById(100, function ($overrides) {
                    foreach ($overrides as $override) {
                        DB::table('platform_listings')
                            ->where('product_id', $override->product_id)
                            ->where('store_marketplace_id', $override->store_marketplace_id)
                            ->update([
                                'title' => $override->title,
                                'description' => $override->description,
                                'attributes' => $override->attributes,
                                'platform_category_id' => $override->category_id,
                                'platform_settings' => $override->platform_settings,
                                'metafield_overrides' => $override->custom_metafields,
                            ]);
                    }
                });
        }

        // 2. Create PlatformListingVariant rows from products' variants
        DB::table('platform_listings')
            ->orderBy('id')
            ->chunkById(100, function ($listings) {
                foreach ($listings as $listing) {
                    if (! $listing->product_id) {
                        continue;
                    }

                    $variants = DB::table('product_variants')
                        ->where('product_id', $listing->product_id)
                        ->whereNull('deleted_at')
                        ->get();

                    foreach ($variants as $variant) {
                        // Skip if already exists
                        $exists = DB::table('platform_listing_variants')
                            ->where('platform_listing_id', $listing->id)
                            ->where('product_variant_id', $variant->id)
                            ->exists();

                        if ($exists) {
                            continue;
                        }

                        DB::table('platform_listing_variants')->insert([
                            'platform_listing_id' => $listing->id,
                            'product_variant_id' => $variant->id,
                            'price' => $variant->price,
                            'quantity' => $variant->quantity,
                            'sku' => $variant->sku,
                            'barcode' => $variant->barcode,
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Truncate the variant table (data migration, not reversible cleanly)
        DB::table('platform_listing_variants')->truncate();

        // Clear the override columns on platform_listings
        DB::table('platform_listings')->update([
            'title' => null,
            'description' => null,
            'attributes' => null,
            'platform_category_id' => null,
            'platform_settings' => null,
            'metafield_overrides' => null,
        ]);
    }
};
