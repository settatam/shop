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
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_marketplace_id')->nullable()->constrained('store_marketplaces')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('platform', 30); // shopify, ebay, amazon, etsy, walmart, woocommerce
            $table->string('event_type', 50); // orders/create, orders/updated, etc.
            $table->string('external_id')->nullable(); // External order/resource ID

            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed, skipped
            $table->text('error_message')->nullable();
            $table->unsignedInteger('retry_count')->default(0);

            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('signature')->nullable(); // For verification logging

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'event_type']);
            $table->index(['store_marketplace_id', 'status']);
            $table->index(['store_id', 'created_at']);
            $table->index('external_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
