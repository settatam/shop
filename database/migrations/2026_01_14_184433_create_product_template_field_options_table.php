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
        Schema::create('product_template_field_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_template_field_id')->constrained()->cascadeOnDelete();
            $table->string('label'); // Display label
            $table->string('value'); // Stored value
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_template_field_id', 'sort_order'], 'template_field_options_field_id_sort_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_template_field_options');
    }
};
