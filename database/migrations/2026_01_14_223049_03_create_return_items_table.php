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
        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('line_total', 10, 2);

            $table->string('condition')->nullable();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();

            $table->boolean('restock')->default(true);
            $table->boolean('restocked')->default(false);
            $table->timestamp('restocked_at')->nullable();

            $table->foreignId('exchange_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->integer('exchange_quantity')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_items');
    }
};
