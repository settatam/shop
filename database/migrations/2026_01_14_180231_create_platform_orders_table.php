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
        Schema::create('platform_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_marketplace_id')->constrained('store_marketplaces')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_order_id'); // Platform's order ID
            $table->string('external_order_number')->nullable(); // Human-readable order number
            $table->string('status'); // Platform order status
            $table->string('fulfillment_status')->nullable();
            $table->string('payment_status')->nullable();
            $table->decimal('total', 10, 2);
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('shipping_cost', 10, 2)->nullable();
            $table->decimal('tax', 10, 2)->nullable();
            $table->decimal('discount', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->json('customer_data')->nullable(); // Customer info from platform
            $table->json('shipping_address')->nullable();
            $table->json('billing_address')->nullable();
            $table->json('line_items')->nullable(); // Raw line items from platform
            $table->json('platform_data')->nullable(); // Full order data
            $table->timestamp('ordered_at');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('store_marketplace_id', 'po_connection_idx');
            $table->index('order_id', 'po_order_idx');
            $table->unique(['store_marketplace_id', 'external_order_id'], 'po_conn_order_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_orders');
    }
};
