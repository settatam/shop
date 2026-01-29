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
        Schema::create('label_template_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_template_id')->constrained()->cascadeOnDelete();
            $table->enum('element_type', ['text_field', 'barcode', 'static_text', 'line']);
            $table->unsignedInteger('x')->default(0);
            $table->unsignedInteger('y')->default(0);
            $table->unsignedInteger('width')->default(100);
            $table->unsignedInteger('height')->default(25);
            $table->string('content')->nullable();
            $table->json('styles')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['label_template_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('label_template_elements');
    }
};
