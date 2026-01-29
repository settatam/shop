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
        Schema::table('product_template_fields', function (Blueprint $table) {
            // Canonical name for cross-platform mapping (e.g., "metal_type", "gemstone", "ring_size")
            $table->string('canonical_name')->nullable()->after('name');
            // Track if this field was AI-generated
            $table->boolean('ai_generated')->default(false)->after('width_class');
        });

        Schema::table('product_templates', function (Blueprint $table) {
            // Track if this template was AI-generated
            $table->boolean('ai_generated')->default(false)->after('is_active');
            // Store the original user prompt that generated this template
            $table->text('generation_prompt')->nullable()->after('ai_generated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_template_fields', function (Blueprint $table) {
            $table->dropColumn(['canonical_name', 'ai_generated']);
        });

        Schema::table('product_templates', function (Blueprint $table) {
            $table->dropColumn(['ai_generated', 'generation_prompt']);
        });
    }
};
