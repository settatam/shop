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
        Schema::create('rapnet_prices', function (Blueprint $table) {
            $table->id();
            $table->string('shape');
            $table->string('color');
            $table->string('clarity');
            $table->decimal('low_size', 8, 2);
            $table->decimal('high_size', 8, 2);
            $table->decimal('carat_price', 10, 2);
            $table->timestamp('price_date')->nullable();
            $table->timestamps();

            // Index for quick lookups
            $table->index(['shape', 'color', 'clarity', 'low_size', 'high_size']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rapnet_prices');
    }
};
