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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 191)->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('cost', 10, 2)->nullable();
            $table->integer('quantity')->default(0);
            $table->string('barcode', 191)->nullable();
            $table->string('status', 191)->nullable();
            $table->tinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->decimal('weight', 10, 4)->nullable();
            $table->string('weight_unit', 20)->nullable();
            $table->string('option1_name', 100)->nullable();
            $table->string('option1_value', 100)->nullable();
            $table->string('option2_name', 100)->nullable();
            $table->string('option2_value', 100)->nullable();
            $table->string('option3_name', 100)->nullable();
            $table->string('option3_value', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('product_id');
            $table->index('sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
