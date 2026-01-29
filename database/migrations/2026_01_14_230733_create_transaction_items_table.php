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
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();

            // Pricing
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('buy_price', 10, 2)->nullable();

            // Precious metal info
            $table->decimal('dwt', 8, 4)->nullable();
            $table->string('precious_metal')->nullable();

            // Status
            $table->string('condition')->nullable();
            $table->boolean('is_added_to_inventory')->default(false);
            $table->timestamp('date_added_to_inventory')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};
