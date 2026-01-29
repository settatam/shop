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
        Schema::create('platform_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->string('external_id'); // Platform's category ID (e.g., eBay category ID)
            $table->string('name');
            $table->string('full_path')->nullable(); // Full category path on platform
            $table->string('parent_external_id')->nullable();
            $table->integer('level')->default(0);
            $table->boolean('is_leaf')->default(false); // Can products be listed here?
            $table->json('required_fields')->nullable(); // Required item specifics/attributes
            $table->json('optional_fields')->nullable(); // Optional but recommended fields
            $table->json('field_values')->nullable(); // Accepted values for each field
            $table->timestamps();

            $table->unique(['platform_id', 'external_id']);
            $table->index(['platform_id', 'is_leaf']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_categories');
    }
};
