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
        Schema::table('template_platform_mappings', function (Blueprint $table) {
            // Stores metafield configuration for platforms like Shopify
            // Format: { "field_name": { "namespace": "custom", "key": "custom_key", "enabled": true } }
            $table->json('metafield_mappings')->nullable()->after('default_values');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('template_platform_mappings', function (Blueprint $table) {
            $table->dropColumn('metafield_mappings');
        });
    }
};
