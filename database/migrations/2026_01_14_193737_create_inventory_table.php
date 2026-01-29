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
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            // Stock levels
            $table->integer('quantity')->default(0);
            $table->integer('reserved_quantity')->default(0); // Reserved for orders
            $table->integer('incoming_quantity')->default(0); // Expected from transfers/POs

            // Reorder settings
            $table->integer('reorder_point')->nullable(); // Alert when below this
            $table->integer('reorder_quantity')->nullable(); // Suggested reorder amount
            $table->integer('safety_stock')->default(0); // Minimum to maintain

            // Location within warehouse
            $table->string('bin_location', 50)->nullable(); // e.g., "A1-B2-C3"
            $table->string('zone')->nullable(); // e.g., "Zone A", "Cold Storage"

            // Cost tracking
            $table->decimal('unit_cost', 12, 4)->nullable(); // Weighted average cost
            $table->decimal('last_cost', 12, 4)->nullable(); // Last purchase cost

            // Tracking
            $table->timestamp('last_counted_at')->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('last_sold_at')->nullable();

            $table->timestamps();

            // Unique constraint: one inventory record per variant per warehouse
            $table->unique(['product_variant_id', 'warehouse_id']);
            $table->index(['store_id', 'warehouse_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
