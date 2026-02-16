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
        Schema::create('template_platform_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_template_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 50);
            $table->json('field_mappings');
            $table->json('default_values')->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->timestamps();

            $table->unique(['product_template_id', 'platform']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_platform_mappings');
    }
};
