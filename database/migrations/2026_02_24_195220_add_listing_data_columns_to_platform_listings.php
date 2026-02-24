<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard against partial re-run (columns may already exist from a failed attempt)
        if (! Schema::hasColumn('platform_listings', 'title')) {
            Schema::table('platform_listings', function (Blueprint $table) {
                $table->string('title', 500)->nullable()->after('status');
                $table->text('description')->nullable()->after('title');
                $table->json('images')->nullable()->after('description');
                $table->json('attributes')->nullable()->after('images');
                $table->string('platform_category_id')->nullable()->after('attributes');
                $table->json('platform_category_options')->nullable()->after('platform_category_id');
                $table->json('platform_settings')->nullable()->after('platform_category_options');
                $table->json('metafield_overrides')->nullable()->after('platform_settings');
            });
        }

        // Deduplicate: keep the most recent listing per (sales_channel_id, product_id)
        $duplicates = DB::table('platform_listings')
            ->select('sales_channel_id', 'product_id')
            ->whereNotNull('sales_channel_id')
            ->whereNotNull('product_id')
            ->groupBy('sales_channel_id', 'product_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            $keepId = DB::table('platform_listings')
                ->where('sales_channel_id', $dup->sales_channel_id)
                ->where('product_id', $dup->product_id)
                ->orderByDesc('updated_at')
                ->value('id');

            DB::table('platform_listings')
                ->where('sales_channel_id', $dup->sales_channel_id)
                ->where('product_id', $dup->product_id)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        // Now add the unique constraint
        Schema::table('platform_listings', function (Blueprint $table) {
            $table->unique(['sales_channel_id', 'product_id'], 'pl_channel_product_unique');
        });

        // Drop old columns that are moving to platform_listing_variants
        Schema::table('platform_listings', function (Blueprint $table) {
            $table->dropForeign(['product_variant_id']);
            $table->dropColumn('product_variant_id');
            $table->dropColumn('external_variant_id');
        });

        // Drop old unique constraint that doesn't make sense anymore
        Schema::table('platform_listings', function (Blueprint $table) {
            $table->dropUnique('pl_conn_listing_unique');
        });
    }

    public function down(): void
    {
        Schema::table('platform_listings', function (Blueprint $table) {
            $table->unique(['store_marketplace_id', 'external_listing_id'], 'pl_conn_listing_unique');
        });

        Schema::table('platform_listings', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('external_variant_id')->nullable()->after('external_listing_id');
        });

        Schema::table('platform_listings', function (Blueprint $table) {
            $table->dropUnique('pl_channel_product_unique');
            $table->dropColumn([
                'title',
                'description',
                'images',
                'attributes',
                'platform_category_id',
                'platform_category_options',
                'platform_settings',
                'metafield_overrides',
            ]);
        });
    }
};
