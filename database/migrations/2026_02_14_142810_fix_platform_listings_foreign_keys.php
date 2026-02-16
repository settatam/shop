<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('platform_listings', function (Blueprint $table) {
            // Drop incorrect foreign keys
            $table->dropForeign(['product_id']);
            $table->dropForeign(['product_variant_id']);
        });

        Schema::table('platform_listings', function (Blueprint $table) {
            // Add correct foreign keys
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_listings', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['product_variant_id']);
        });

        Schema::table('platform_listings', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('store_marketplaces')->cascadeOnDelete();
            $table->foreign('product_variant_id')->references('id')->on('store_marketplaces')->cascadeOnDelete();
        });
    }
};
