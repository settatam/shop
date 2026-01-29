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
            // Group name to group related fields (e.g., 'weight' for weight + unit)
            $table->string('group_name')->nullable()->after('sort_order');
            // Position within the group (1 = main field, 2 = suffix/unit selector)
            $table->tinyInteger('group_position')->default(1)->after('group_name');
            // Width class for layout (e.g., 'full', 'half', 'third', 'quarter')
            $table->string('width_class')->default('full')->after('group_position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_template_fields', function (Blueprint $table) {
            $table->dropColumn(['group_name', 'group_position', 'width_class']);
        });
    }
};
