<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_listing_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_listing_id')->constrained('platform_listings')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('external_variant_id')->nullable();
            $table->string('external_inventory_item_id')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->integer('quantity')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->json('platform_data')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['platform_listing_id', 'product_variant_id'], 'plv_listing_variant_unique');
            $table->index('external_variant_id');
            $table->index('external_inventory_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_listing_variants');
    }
};
