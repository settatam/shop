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
        Schema::create('metal_prices', function (Blueprint $table) {
            $table->id();
            $table->string('metal_type'); // gold, silver, platinum, palladium
            $table->string('purity')->nullable(); // 24k, 22k, 18k, 14k, 10k, sterling, etc.
            $table->decimal('price_per_gram', 12, 4);
            $table->decimal('price_per_ounce', 12, 4);
            $table->decimal('price_per_dwt', 12, 4)->nullable(); // pennyweight
            $table->string('currency', 3)->default('USD');
            $table->string('source')->nullable(); // API source
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->index(['metal_type', 'purity']);
            $table->index('effective_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metal_prices');
    }
};
