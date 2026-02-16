<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_platform_overrides', function (Blueprint $table) {
            // Image control
            $table->json('excluded_image_ids')->nullable()->after('attributes')
                ->comment('Array of image IDs to exclude from this platform');
            $table->json('image_order')->nullable()->after('excluded_image_ids')
                ->comment('Custom image order for this platform');

            // Metafield control (for Shopify-like platforms)
            $table->json('excluded_metafields')->nullable()->after('image_order')
                ->comment('Array of metafield keys to exclude');
            $table->json('custom_metafields')->nullable()->after('excluded_metafields')
                ->comment('Custom metafields to add for this platform');

            // Attribute overrides (per-field control)
            $table->json('attribute_overrides')->nullable()->after('custom_metafields')
                ->comment('Per-attribute overrides: {field: {value, enabled, platform_field}}');

            // Platform-specific settings
            $table->json('platform_settings')->nullable()->after('attribute_overrides')
                ->comment('Platform-specific settings (policies, condition, etc.)');
        });
    }

    public function down(): void
    {
        Schema::table('product_platform_overrides', function (Blueprint $table) {
            $table->dropColumn([
                'excluded_image_ids',
                'image_order',
                'excluded_metafields',
                'custom_metafields',
                'attribute_overrides',
                'platform_settings',
            ]);
        });
    }
};
