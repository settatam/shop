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
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Display name: "Main Store", "eBay Store 1", "Shopify - Wholesale"
            $table->string('code')->index(); // Unique code for this channel: "main_store", "ebay_1", "shopify_wholesale"
            $table->string('type'); // local, shopify, ebay, amazon, etsy, walmart, woocommerce
            $table->boolean('is_local')->default(false); // True for local/in-store channels
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete(); // For local channels
            $table->foreignId('store_marketplace_id')->nullable()->constrained()->nullOnDelete(); // For external platforms
            $table->string('color')->nullable(); // For display in reports
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // Default channel for new orders
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'code']);
        });

        // Add sales_channel_id to orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('sales_channel_id')->nullable()->after('store_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['sales_channel_id']);
            $table->dropColumn('sales_channel_id');
        });

        Schema::dropIfExists('sales_channels');
    }
};
