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
        Schema::create('repair_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();

            // Pricing
            $table->decimal('vendor_cost', 10, 2)->default(0);
            $table->decimal('customer_cost', 10, 2)->default(0);

            // Metadata
            $table->string('status')->default('pending');
            $table->decimal('dwt', 8, 4)->nullable();
            $table->string('precious_metal')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repair_items');
    }
};
