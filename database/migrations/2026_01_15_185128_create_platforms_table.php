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
        Schema::create('platforms', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // eBay, Amazon, Etsy, Shopify, Google Shopping
            $table->string('slug')->unique(); // ebay, amazon, etsy, shopify, google_shopping
            $table->string('logo_url')->nullable();
            $table->text('description')->nullable();
            $table->string('api_base_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // Platform-specific settings
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platforms');
    }
};
