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
        Schema::create('fulfillment_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('handling_time_value')->default(1);
            $table->string('handling_time_unit')->default('DAY');
            $table->string('shipping_type')->default('flat_rate');
            $table->decimal('domestic_shipping_cost', 10, 2)->nullable();
            $table->decimal('international_shipping_cost', 10, 2)->nullable();
            $table->boolean('free_shipping')->default(false);
            $table->string('shipping_carrier')->nullable();
            $table->string('shipping_service')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fulfillment_policies');
    }
};
