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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('sub_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('sales_tax', 12, 2)->nullable();
            $table->decimal('shipping_cost', 12, 2)->nullable();
            $table->decimal('discount_cost', 12, 2)->nullable();
            $table->decimal('shipping_weight', 10, 4)->nullable();

            $table->string('status', 30)->default('pending');
            $table->string('invoice_number')->nullable();
            $table->text('notes')->nullable();

            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();

            $table->unsignedBigInteger('payment_gateway_id')->nullable();
            $table->unsignedBigInteger('shipping_gateway_id')->nullable();
            $table->unsignedBigInteger('cart_id')->nullable();
            $table->string('order_id', 100)->nullable();
            $table->string('shipstation_store')->nullable();
            $table->string('square_order_id')->nullable();
            $table->string('external_marketplace_id')->nullable();
            $table->string('source_platform')->nullable();

            $table->date('date_of_purchase')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'customer_id']);
            $table->index('invoice_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
