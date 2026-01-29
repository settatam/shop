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
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained('inventory')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Adjustment details
            $table->string('reference', 50)->nullable(); // ADJ-001
            $table->string('type', 30); // damaged, lost, found, correction, cycle_count, shrinkage, write_off
            $table->integer('quantity_before');
            $table->integer('quantity_change'); // Can be positive or negative
            $table->integer('quantity_after');

            // Cost impact
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->decimal('total_cost_impact', 12, 4)->nullable();

            $table->string('reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'type']);
            $table->index(['store_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};
