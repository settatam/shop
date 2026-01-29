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
        Schema::create('purchase_order_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('received_by')->constrained('users')->cascadeOnDelete();
            $table->string('receipt_number')->unique();
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'purchase_order_id']);
            $table->index(['store_id', 'received_at']);
        });

        Schema::create('purchase_order_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_adjustment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity_received');
            $table->decimal('unit_cost', 10, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_receipt_id', 'purchase_order_item_id'], 'po_receipt_items_receipt_item_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_receipt_items');
        Schema::dropIfExists('purchase_order_receipts');
    }
};
