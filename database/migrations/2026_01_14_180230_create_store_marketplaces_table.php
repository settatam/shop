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
        Schema::create('store_marketplaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // shopify, ebay, amazon, etsy, walmart, woocommerce
            $table->string('name')->nullable(); // Friendly name for the connection
            $table->string('shop_domain')->nullable(); // For Shopify
            $table->string('external_store_id')->nullable(); // Platform's store ID
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('credentials')->nullable(); // Additional OAuth data
            $table->json('settings')->nullable(); // Platform-specific settings
            $table->string('status')->default('active'); // active, inactive, error
            $table->text('last_error')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'platform']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_marketplaces');
    }
};
