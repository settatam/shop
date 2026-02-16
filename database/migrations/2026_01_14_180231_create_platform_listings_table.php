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
        Schema::create('platform_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_marketplace_id')->constrained('store_marketplaces')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->string('external_listing_id')->nullable(); // Platform's listing ID
            $table->string('external_variant_id')->nullable(); // Platform's variant ID
            $table->string('status')->default('draft'); // draft, active, inactive, error
            $table->string('listing_url')->nullable();
            $table->decimal('platform_price', 10, 2)->nullable();
            $table->integer('platform_quantity')->nullable();
            $table->json('platform_data')->nullable(); // Full listing data from platform
            $table->json('category_mapping')->nullable(); // Platform category IDs
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('store_marketplace_id', 'pl_connection_idx');
            $table->index('product_id', 'pl_product_idx');
            $table->unique(['store_marketplace_id', 'external_listing_id'], 'pl_conn_listing_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_listings');
    }
};
