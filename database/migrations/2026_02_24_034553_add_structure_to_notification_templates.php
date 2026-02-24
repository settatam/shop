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
        Schema::table('notification_templates', function (Blueprint $table) {
            // JSON structure defining the report layout (tables, columns, etc.)
            $table->json('structure')->nullable()->after('content');

            // The type of template: 'custom' (twig), 'structured' (generated from structure)
            $table->string('template_type')->default('custom')->after('structure');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->dropColumn(['structure', 'template_type']);
        });
    }
};
