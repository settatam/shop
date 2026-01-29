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
        Schema::create('platform_field_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_template_field_id')->constrained()->cascadeOnDelete();
            $table->string('platform_field_name'); // The field name on the platform (e.g., "Metal" for eBay)
            $table->string('platform_field_id')->nullable(); // Platform's internal field ID if applicable
            $table->boolean('is_required')->default(false);
            $table->boolean('is_recommended')->default(false);
            $table->json('value_mappings')->nullable(); // Map our values to platform's accepted values
            $table->json('accepted_values')->nullable(); // Platform's list of accepted values
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['platform_id', 'product_template_field_id'], 'platform_field_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_field_mappings');
    }
};
