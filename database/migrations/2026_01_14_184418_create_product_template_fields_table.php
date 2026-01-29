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
        Schema::create('product_template_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_template_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Slug/identifier (e.g., "color_family")
            $table->string('label'); // Display label (e.g., "Color Family")
            $table->string('type'); // Field type: text, textarea, select, checkbox, radio, number, date
            $table->text('placeholder')->nullable();
            $table->text('help_text')->nullable();
            $table->text('default_value')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->boolean('show_in_listing')->default(false);
            $table->json('validation_rules')->nullable(); // Additional validation rules
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_template_id', 'sort_order']);
            $table->unique(['product_template_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_template_fields');
    }
};
